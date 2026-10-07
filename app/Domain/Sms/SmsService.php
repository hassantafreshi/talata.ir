<?php

namespace App\Domain\Sms;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Invoices\Numbering;
use App\Domain\Plans\Entitlements;
use App\Jobs\SendSms;
use App\Models\Customer;
use App\Models\InstallmentLine;
use App\Models\Invoice;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Support\DbLock;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * All outgoing SMS go through here. Invoice and reminder messages pass a layered guard so that
 * neither a free nor a paid account can be used to send useless or abusive SMS:
 *  1. capability + issued (never draft/void) invoice with a valid Iranian mobile;
 *  2. fixed template only (no free text, no foreign links/numbers) and a complete shop profile;
 *  3. per-invoice cap and resend cooldown, resend only after a failed/unknown/credit-wait attempt;
 *  4. per-recipient caps (per tenant and, for free SMS, across all tenants) and recipient opt-out;
 *  5. per-tenant hourly and daily caps by plan; free yearly SMS also capped per day;
 *  6. charging (free yearly allowance, else prepaid credit) happens under a tenant row lock and a
 *     per-recipient advisory lock, so concurrent requests cannot overspend or bypass caps.
 */
final class SmsService
{
    public function __construct(private readonly Entitlements $entitlements, private readonly SmsCredit $credit) {}

    /** Login OTP: operational budget, never tenant credit. Limits are enforced by OtpService before this. */
    public static function otpBody(string $code, string $kind = 'login'): string
    {
        if ($kind === 'mobile_change') {
            return "کد تغییر شماره ورود زرلیو: {$code}\nاگر خودتان درخواست نکرده‌اید، این کد را به هیچ‌کس ندهید.";
        }

        return "کد ورود زرلیو: {$code}\nاین کد را به کسی ندهید.";
    }

    /** OTP purposes (OtpService) → SMS wording kind. */
    public static function otpKind(string $purpose): string
    {
        return in_array($purpose, ['mch_old', 'mch_new'], true) ? 'mobile_change' : 'login';
    }

    public function queueOtp(string $mobile, string $code, string $challengeId, string $purpose = 'user'): SmsMessage
    {
        $kind = self::otpKind($purpose);
        $message = SmsMessage::create([
            'tenant_id' => null, 'purpose' => 'OTP', 'recipient' => $mobile,
            'body' => self::otpBody('••••••', $kind), 'payload' => ['code' => $code, 'kind' => $kind],
            'segments' => 1, 'cost_irr' => '0', 'charge_source' => 'OPERATIONAL', 'status' => 'QUEUED',
            'idempotency_key' => 'otp:'.$challengeId,
        ]);
        SendSms::dispatch($message->id)->onQueue('otp');

        return $message;
    }

    public function templateFor(Tenant $tenant): string
    {
        if ($this->entitlements->can($tenant, 'sms.template_edit')) {
            $custom = SmsSetting::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->value('invoice_template');
            if ($custom) {
                return $custom;
            }
        }

        return SmsTemplate::DEFAULT;
    }

    /** Same length as a real share link (32-byte token = 43 base64url chars), so the review counts real segments. */
    public static function linkPlaceholder(): string
    {
        return config('talata.public_url').'/i/'.str_repeat('•', 43);
    }

    public function previewFor(Invoice $invoice, Tenant $tenant, ?string $link = null): array
    {
        $link ??= self::linkPlaceholder();
        // A draft has no number yet: preview with the number it would get now (same shape and length).
        $number = $invoice->number ?? (Numbering::preview(Numbering::settings(), CarbonImmutable::now(), $tenant->timezone)[0] ?? null);
        $body = SmsTemplate::render($this->templateFor($tenant), [
            'shop_name' => $invoice->snapshot['shop']['name'] ?? $tenant->profile?->name ?? '',
            'invoice_number' => $number ? Digits::invoiceNumber($number) : '—',
            // Gold received can exceed the sale: then the balance is owed to the customer.
            'amount' => str_starts_with((string) $invoice->payable_irr, '-')
                ? Money::toman(ltrim((string) $invoice->payable_irr, '-')).' تومان به نفع شما'
                : Money::toman($invoice->payable_irr).' تومان',
            'invoice_link' => $link,
        ]);
        $segments = Segments::count($body);
        $perSegment = $this->entitlements->smsPerSegmentIrr($tenant);

        return [
            'body' => $body, 'segments' => $segments,
            'cost_irr' => (string) BigInteger::of($perSegment)->multipliedBy($segments),
            'free_remaining' => $this->entitlements->freeSmsRemaining($tenant),
            'balance_irr' => $this->credit->balance($tenant->id),
            'per_segment_irr' => $perSegment,
        ];
    }

    /**
     * Queues the invoice SMS to the customer (initial or resend). Returns the message, which may be in
     * AWAITING_CREDIT when neither free allowance nor credit covers it. A resend is refused while the previous
     * one is still on its way or its outcome is unknown (it could arrive twice); after a delivered one it needs
     * $confirmed, because it is charged again.
     */
    public function queueInvoiceSms(Tenant $tenant, Invoice $invoice, string $link, ?int $userId, bool $isResend, bool $confirmed = false): SmsMessage
    {
        return DB::transaction(function () use ($tenant, $invoice, $link, $userId, $isResend, $confirmed) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $cfg = config('talata.sms');
            $this->assertInvoiceSendable($tenant, $invoice);
            $recipient = $invoice->buyer_mobile;
            if (! $recipient) {
                throw new DomainError('SMS_NO_RECIPIENT', 'شماره موبایل مشتری روی این فاکتور ثبت نشده است.');
            }
            if ($invoice->customer_id && Customer::query()->whereKey($invoice->customer_id)->value('sms_opt_out')) {
                throw new DomainError('SMS_OPTED_OUT', 'این مشتری دریافت پیامک را غیرفعال کرده است.');
            }

            // Serialize per recipient across tenants (global caps).
            DbLock::key('sms:'.$recipient);

            $previous = SmsMessage::query()->where('invoice_id', $invoice->id)->where('purpose', 'INVOICE')->orderByDesc('id')->get();
            $last = $previous->first();
            if ($last && ! $isResend) {
                return $last; // idempotent initial send
            }
            if ($previous->where('status', '!=', 'CANCELLED')->count() >= $cfg['max_sends_per_invoice']) {
                throw new DomainError('SMS_INVOICE_LIMIT', 'برای این فاکتور بیشتر از '.Digits::toPersian((string) $cfg['max_sends_per_invoice']).' بار نمی‌توان پیامک فرستاد. لینک را کپی کنید.', 429);
            }
            if ($last) {
                if (in_array($last->status, ['QUEUED', 'SENDING'], true)) {
                    throw new DomainError('SMS_IN_PROGRESS', 'پیامک قبلی هنوز در حال ارسال است. چند لحظه بعد وضعیت آن را ببینید.', 409);
                }
                // Outcome not known yet: a resend could reach the customer twice. Reconciliation settles it
                // (delivered, or failed and refunded within about 30 minutes); then a resend is allowed.
                if ($last->status === 'UNKNOWN') {
                    throw new DomainError('SMS_STATUS_UNKNOWN', 'وضعیت پیامک قبلی هنوز روشن نیست. تا روشن شدن آن (حداکثر حدود ۳۰ دقیقه) ارسال دوباره ممکن نیست تا پیامک تکراری نرود.', 409);
                }
                if ($last->status !== 'AWAITING_CREDIT' && $last->created_at->gt(now()->subMinutes($cfg['resend_min_minutes']))) {
                    throw new DomainError('SMS_RESEND_COOLDOWN', 'ارسال دوباره تا '.Digits::toPersian((string) $cfg['resend_min_minutes']).' دقیقه پس از پیامک قبلی ممکن نیست.', 429);
                }
                if (in_array($last->status, ['SENT', 'DELIVERED'], true) && ! $confirmed) {
                    throw new DomainError('SMS_CONFIRM_RESEND', 'پیامک قبلی برای مشتری فرستاده شده است. دوباره بفرستیم؟ هزینه آن دوباره حساب می‌شود.', 409);
                }
                if ($last->status === 'AWAITING_CREDIT') {
                    $last->update(['status' => 'CANCELLED', 'last_error' => 'superseded']);
                }
            }

            return $this->queueOne($tenant, $invoice, $recipient, 'INVOICE', 'inv:'.$invoice->id.':'.($previous->count() + 1), $link, $userId,
                $isResend ? 'sms.resend_queued' : 'sms.queued', true);
        });
    }

    /**
     * Copies of the invoice SMS to other numbers the merchant typed (a family member, a second phone). Same
     * text, link, caps and charging as the customer SMS; never waits for credit (the merchant is right there).
     * At most max_copy_recipients_per_send numbers per request and max_copies_per_invoice per invoice; a number
     * that already has a copy on its way or delivered is skipped. $requestKey makes a double tap a replay.
     *
     * @param  list<string>  $mobiles  normalised 09… numbers (Mobile::extractAll)
     * @return list<array{mobile: string, status: string, code?: string, message_fa?: string}>
     */
    public function queueInvoiceCopies(Tenant $tenant, Invoice $invoice, string $link, ?int $userId, array $mobiles, string $requestKey): array
    {
        return DB::transaction(function () use ($tenant, $invoice, $link, $userId, $mobiles, $requestKey) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $cfg = config('talata.sms');
            $this->assertInvoiceSendable($tenant, $invoice);
            $mobiles = array_values(array_unique(array_filter($mobiles)));
            if (! $mobiles) {
                throw new DomainError('SMS_NO_RECIPIENT', 'شماره موبایلی وارد نشده است.', 422);
            }
            if (count($mobiles) > $cfg['max_copy_recipients_per_send']) {
                throw new DomainError('SMS_TOO_MANY_RECIPIENTS', 'هر بار حداکثر '.Digits::toPersian((string) $cfg['max_copy_recipients_per_send']).' شماره.', 422);
            }

            $results = [];
            foreach ($mobiles as $mobile) {
                $key = 'copy:'.$invoice->id.':'.substr(hash('sha256', $requestKey.'|'.$mobile), 0, 40);
                if ($replay = SmsMessage::query()->where('idempotency_key', $key)->first()) {
                    $results[] = ['mobile' => $mobile, 'status' => $replay->status];

                    continue;
                }
                if ($mobile === $invoice->buyer_mobile) {
                    $results[] = ['mobile' => $mobile, 'status' => 'SKIPPED', 'code' => 'SMS_IS_CUSTOMER', 'message_fa' => 'این شماره خود مشتری است؛ «ارسال دوباره به مشتری» را بزنید.'];

                    continue;
                }
                DbLock::key('sms:'.$mobile);
                $copies = SmsMessage::query()->where('invoice_id', $invoice->id)->where('purpose', 'INVOICE_COPY')->whereNotIn('status', ['CANCELLED', 'FAILED']);
                if ((clone $copies)->where('recipient', $mobile)->exists()) {
                    $results[] = ['mobile' => $mobile, 'status' => 'SKIPPED', 'code' => 'SMS_ALREADY_SENT', 'message_fa' => 'این فاکتور قبلاً برای این شماره فرستاده شده است.'];

                    continue;
                }
                if ($copies->count() >= $cfg['max_copies_per_invoice']) {
                    $results[] = ['mobile' => $mobile, 'status' => 'NOT_SENT', 'code' => 'SMS_COPY_LIMIT', 'message_fa' => 'برای هر فاکتور حداکثر '.Digits::toPersian((string) $cfg['max_copies_per_invoice']).' شماره دیگر. لینک را کپی کنید.'];

                    continue;
                }
                try {
                    $m = $this->queueOne($tenant, $invoice, $mobile, 'INVOICE_COPY', $key, $link, $userId, 'sms.copy_queued', false);
                    $results[] = $m->status === 'CANCELLED'
                        ? ['mobile' => $mobile, 'status' => 'NOT_SENT', 'code' => 'SMS_NO_CREDIT', 'message_fa' => 'اعتبار پیامک کافی نیست. ابتدا اعتبار پیامک بخرید.']
                        : ['mobile' => $mobile, 'status' => $m->status];
                } catch (DomainError $e) {
                    $results[] = ['mobile' => $mobile, 'status' => 'NOT_SENT', 'code' => $e->codeName, 'message_fa' => $e->messageFa];
                }
            }

            return $results;
        });
    }

    private function assertInvoiceSendable(Tenant $tenant, Invoice $invoice): void
    {
        $this->entitlements->assertCan($tenant, 'invoice.sms_share', 'ارسال پیامک فاکتور در این پلن فعال نیست.');
        if (! $invoice->isIssued()) {
            throw new DomainError('SMS_INVOICE_NOT_ISSUED', 'فقط برای فاکتور قطعی و باطل‌نشده می‌توان پیامک فرستاد.');
        }
        if (config('talata.sms.requires_complete_profile') && ! $tenant->profile?->isComplete()) {
            throw new DomainError('PROFILE_INCOMPLETE', 'ابتدا اطلاعات کسب‌وکار را کامل کنید.');
        }
    }

    /**
     * Caps, content check, charging and dispatch shared by the customer SMS and its copies. Only DomainErrors
     * before any write, so a caller may catch one and continue in the same transaction.
     */
    private function queueOne(Tenant $tenant, Invoice $invoice, string $recipient, string $purpose, string $key, string $link, ?int $userId, string $auditEvent, bool $awaitCredit): SmsMessage
    {
        $cfg = config('talata.sms');
        $active = fn ($q) => $q->whereNotIn('status', ['CANCELLED', 'AWAITING_CREDIT', 'FAILED']);
        // Tenant-wide caps also count failed attempts: invalid-number sends must not be free to repeat.
        $attempted = fn ($q) => $q->whereNotIn('status', ['CANCELLED', 'AWAITING_CREDIT']);
        $recipientToday = SmsMessage::query()->forTenant($tenant->id)->where('recipient', $recipient)->where('created_at', '>=', now()->subDay())->where($active)->count();
        if ($recipientToday >= $cfg['per_recipient_per_tenant_daily']) {
            throw new DomainError('SMS_RECIPIENT_DAILY', 'به این شماره امروز به اندازه کافی پیامک فرستاده شده است. فردا دوباره امتحان کنید.', 429);
        }
        $planCode = $this->entitlements->planCode($tenant);
        $tenantHour = SmsMessage::query()->forTenant($tenant->id)->where('purpose', '!=', 'OTP')->where('created_at', '>=', now()->subHour())->where($attempted)->count();
        $tenantDay = SmsMessage::query()->forTenant($tenant->id)->where('purpose', '!=', 'OTP')->where('created_at', '>=', now()->subDay())->where($attempted)->count();
        if ($tenantHour >= $cfg['tenant_hourly_cap'] || $tenantDay >= ($cfg['tenant_daily_cap'][$planCode] ?? 0)) {
            throw new DomainError('SMS_TENANT_RATE', 'سقف ارسال پیامک این فروشگاه در این بازه پر شده است. کمی بعد دوباره امتحان کنید.', 429);
        }

        $shopName = (string) ($invoice->snapshot['shop']['name'] ?? $tenant->profile?->name ?? '');
        SmsTemplate::assertSafeToSend($this->templateFor($tenant), $shopName, Digits::invoiceNumber($invoice->number), (bool) $tenant->profile?->isNameApproved($shopName));
        $preview = $this->previewFor($invoice, $tenant, $link);

        $useFree = $preview['free_remaining'] > 0 && $preview['segments'] <= 2;
        if ($useFree) {
            $freeToday = SmsMessage::query()->forTenant($tenant->id)->where('charge_source', 'FREE_YEARLY')->where('created_at', '>=', now()->subDay())->where($active)->count();
            $freeRecipient = SmsMessage::query()->where('recipient', $recipient)->where('charge_source', 'FREE_YEARLY')->where('created_at', '>=', now()->subDay())->where($active)->count();
            $useFree = $freeToday < $cfg['free_yearly_per_tenant_daily'] && $freeRecipient < $cfg['per_recipient_global_free_daily'];
        }
        if (! $useFree && ! $awaitCredit && BigInteger::of($this->credit->balance($tenant->id))->isLessThan($preview['cost_irr'])) {
            throw new DomainError('SMS_NO_CREDIT', 'اعتبار پیامک کافی نیست (هزینه هر پیامک '.Money::toman($preview['cost_irr']).' تومان). ابتدا اعتبار پیامک بخرید.', 409);
        }

        $message = new SmsMessage([
            'tenant_id' => $tenant->id, 'purpose' => $purpose, 'invoice_id' => $invoice->id, 'recipient' => $recipient,
            'body' => $preview['body'], 'segments' => $preview['segments'], 'cost_irr' => '0', 'charge_source' => 'NONE',
            'status' => 'QUEUED', 'requested_by' => $userId, 'idempotency_key' => $key,
        ]);
        if ($useFree) {
            $message->charge_source = 'FREE_YEARLY';
            $message->save();
        } else {
            $message->charge_source = 'CREDIT';
            $message->cost_irr = $preview['cost_irr'];
            $message->save();
            if (! $this->credit->reserve($tenant->id, $preview['cost_irr'], $message)) {
                $message->update(['status' => $awaitCredit ? 'AWAITING_CREDIT' : 'CANCELLED', 'cost_irr' => '0', 'charge_source' => 'NONE', 'last_error' => $awaitCredit ? null : 'no credit']);
                Audit::record($awaitCredit ? 'sms.awaiting_credit' : 'sms.no_credit', $invoice, ['message' => $message->public_id], $tenant->id);

                return $message;
            }
        }

        Audit::record($auditEvent, $invoice, ['message' => $message->public_id, 'charge' => $message->charge_source, 'segments' => $message->segments], $tenant->id);
        SendSms::dispatch($message->id)->afterCommit();

        return $message;
    }

    public function queueReminder(Tenant $tenant, InstallmentLine $line, string $body, string $recipient, string $kind): ?SmsMessage
    {
        return DB::transaction(function () use ($tenant, $line, $body, $recipient, $kind) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $key = 'rem:'.$line->id.':'.$kind; // pre | late
            if (SmsMessage::query()->where('idempotency_key', $key)->exists()) {
                return null;
            }
            $cfg = config('talata.sms');
            $planCode = $this->entitlements->planCode($tenant);
            $attempted = fn ($q) => $q->whereNotIn('status', ['CANCELLED', 'AWAITING_CREDIT']);
            $tenantDay = SmsMessage::query()->forTenant($tenant->id)->where('purpose', '!=', 'OTP')->where('created_at', '>=', now()->subDay())->where($attempted)->count();
            $tenantHour = SmsMessage::query()->forTenant($tenant->id)->where('purpose', '!=', 'OTP')->where('created_at', '>=', now()->subHour())->where($attempted)->count();
            $recipientToday = SmsMessage::query()->forTenant($tenant->id)->where('recipient', $recipient)->where('created_at', '>=', now()->subDay())->where($attempted)->count();
            if ($tenantDay >= ($cfg['tenant_daily_cap'][$planCode] ?? 0) || $tenantHour >= $cfg['tenant_hourly_cap'] || $recipientToday >= $cfg['per_recipient_per_tenant_daily']) {
                return null; // retried on a later run; the key stays unused
            }
            $recipientMonth = SmsMessage::query()->where('purpose', 'REMINDER')->where('recipient', $recipient)->where('created_at', '>=', now()->subDays(30))->where($attempted)->count();
            if ($recipientMonth >= $cfg['reminders_per_recipient_monthly']) {
                return null; // across all shops: a number cannot be flooded with reminders
            }
            $segments = Segments::count($body);
            $cost = (string) BigInteger::of($this->entitlements->smsPerSegmentIrr($tenant))->multipliedBy($segments);
            $message = SmsMessage::create([
                'tenant_id' => $tenant->id, 'purpose' => 'REMINDER', 'schedule_line_id' => $line->id, 'recipient' => $recipient,
                'body' => $body, 'segments' => $segments, 'cost_irr' => $cost, 'charge_source' => 'CREDIT', 'status' => 'QUEUED',
                'idempotency_key' => $key,
            ]);
            if (! $this->credit->reserve($tenant->id, $cost, $message)) {
                $message->update(['status' => 'AWAITING_CREDIT', 'cost_irr' => '0', 'charge_source' => 'NONE']);

                return $message;
            }
            SendSms::dispatch($message->id)->afterCommit();

            return $message;
        });
    }

    /**
     * Applies a provider status report (reconcile job and the admin «استعلام»). FAILED = never charged
     * (refund); UNDELIVERED = charged but not delivered (stays SENT with the reason). Returns false when
     * the provider does not know yet (UNKNOWN).
     */
    public function applyProviderReport(SmsMessage $m, string $status, ?string $providerId = null): bool
    {
        if ($status === 'UNKNOWN') {
            $m->touch();

            return false;
        }
        if ($status === 'UNDELIVERED') {
            if ($m->status !== 'SENT') {
                $this->applyOutcome($m, 'SENT', $providerId);
            }
            SmsMessage::query()->whereKey($m->id)->update(['last_error' => 'undelivered (provider report)', 'updated_at' => now()]);
        } elseif ($m->status === 'SENT' && $status === 'FAILED') {
            SmsMessage::query()->whereKey($m->id)->update(['last_error' => 'failed after send (provider report)', 'updated_at' => now()]);
        } elseif ($status !== $m->status) {
            $this->applyOutcome($m, $status, $providerId);
        } else {
            $m->touch();
        }

        return true;
    }

    /** Applies a provider outcome; releases credit on definite failure, captures on success. */
    public function applyOutcome(SmsMessage $message, string $status, ?string $providerId = null, ?string $error = null): void
    {
        DB::transaction(function () use ($message, $status, $providerId, $error) {
            $message = SmsMessage::query()->lockForUpdate()->find($message->id);
            if (in_array($message->status, SmsMessage::FINAL, true)) {
                return;
            }
            $message->status = $status;
            $message->provider_message_id = $providerId ?? $message->provider_message_id;
            $message->last_error = $error ? mb_substr($error, 0, 250) : null;
            if (in_array($status, ['SENT', 'DELIVERED'], true)) {
                $message->sent_at ??= now();
                if ($status === 'DELIVERED') {
                    $message->delivered_at = now();
                }
                if ($message->charge_source === 'CREDIT') {
                    $this->credit->capture($message);
                }
            }
            if ($status === 'FAILED' && $message->charge_source === 'CREDIT') {
                $this->credit->release($message);
            }
            $message->save();
        });
    }

    /**
     * Credit arrived (top-up or admin adjustment): queue the shop's invoice SMS that were waiting for credit,
     * oldest first, until the credit runs out. Only for invoices that are still issued and messages from the
     * last `awaiting_credit_max_days`; a voided invoice's waiting message is cancelled, never announced.
     */
    public function releaseAwaitingCredit(Tenant $tenant): int
    {
        return DB::transaction(function () use ($tenant) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $waiting = SmsMessage::query()->forTenant($tenant->id)->where('purpose', 'INVOICE')->where('status', 'AWAITING_CREDIT')
                ->where('created_at', '>=', now()->subDays((int) config('talata.sms.awaiting_credit_max_days', 7)))
                ->orderBy('id')->lockForUpdate()->get();
            $perSegment = $this->entitlements->smsPerSegmentIrr($tenant);
            $queued = 0;
            foreach ($waiting as $m) {
                if (! Invoice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereKey($m->invoice_id)->where('status', 'issued')->exists()) {
                    $m->update(['status' => 'CANCELLED', 'last_error' => 'invoice no longer issued']);

                    continue;
                }
                $cost = (string) BigInteger::of($perSegment)->multipliedBy($m->segments);
                $m->forceFill(['charge_source' => 'CREDIT', 'cost_irr' => $cost, 'status' => 'QUEUED'])->save();
                if (! $this->credit->reserve($tenant->id, $cost, $m)) {
                    $m->update(['status' => 'AWAITING_CREDIT', 'cost_irr' => '0', 'charge_source' => 'NONE']);
                    break;
                }
                Audit::record('sms.queued_after_credit', null, ['message' => $m->public_id, 'segments' => $m->segments], $tenant->id, 'system');
                SendSms::dispatch($m->id)->afterCommit();
                $queued++;
            }

            return $queued;
        });
    }
}
