<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\OtpService;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsService;
use App\Jobs\NotifyProformaConfirmed;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Proforma;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use App\Support\Money;
use App\Support\Tokens;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * پیش‌فاکتور (docs/PROFORMA.md). A priced, numbered copy of a draft that the customer opens from an SMS/shared
 * link and confirms with their own mobile and an SMS code before its validity ends (default 24 h, set by the
 * shop). While it is open the draft is locked (status «proforma»). On confirmation the draft is issued as the
 * sales invoice at the same rate and amount. Expired or cancelled ones stay visible as «ابطال شده».
 */
class ProformaService
{
    /** Validity choices offered to the shop (hours); 24 is the default. */
    public const HOURS = [1, 3, 6, 12, 24, 48, 72];

    public const DEFAULT_HOURS = 24;

    public function __construct(private readonly InvoiceService $invoices, private readonly SmsService $sms, private readonly OtpService $otp) {}

    /**
     * «صدور فاکتور پس از تأیید مشتری»: automatic unless a shop allowed to configure it (proforma.configure,
     * Basic/Professional) chose «دستی». A shop that loses the capability falls back to automatic.
     */
    public static function autoIssue(Tenant $tenant): bool
    {
        if (! app(Entitlements::class)->can($tenant, 'proforma.configure')) {
            return true;
        }
        $v = SmsSetting::query()->value('proforma_auto_issue');

        return $v === null ? true : (bool) $v;
    }

    public static function defaultHours(): int
    {
        $h = (int) SmsSetting::query()->value('proforma_valid_hours');

        return in_array($h, self::HOURS, true) ? $h : self::DEFAULT_HOURS;
    }

    public static function link(Proforma $p): string
    {
        return rtrim((string) config('talata.public_url'), '/').'/p/'.$p->token;
    }

    /** «تا ۱۴۰۵/۰۷/۱۷ ساعت ۱۴:۳۰» */
    public static function until(Proforma $p, string $tz): string
    {
        return 'تا '.Jalali::date($p->expires_at, $tz).' ساعت '.Jalali::time($p->expires_at, $tz);
    }

    public static function hoursFa(int $hours): string
    {
        return $hours % 24 === 0 && $hours >= 48 ? Digits::toPersian((string) ($hours / 24)).' روز' : Digits::toPersian((string) $hours).' ساعت';
    }

    public static function amountFa(Proforma $p): string
    {
        $irr = (string) $p->payable_irr;

        return str_starts_with($irr, '-') ? Money::toman(ltrim($irr, '-')).' تومان به نفع شما' : Money::toman($irr).' تومان';
    }

    /** SMS and share text: shop, amount, validity, link. */
    public static function smsBody(Proforma $p, string $link, string $tz): string
    {
        $shop = (string) ($p->snapshot['shop']['name'] ?? '');

        // Kept to two SMS segments for a typical shop name (the free allowance covers up to two).
        return "پیش‌فاکتور {$shop}\nمبلغ ".self::amountFa($p)."\nمهلت تأیید ".self::until($p, $tz)."\n{$link}";
    }

    /**
     * Sends the draft as a پیش‌فاکتور. Returns [proforma, sms result or DomainError|null]. An SMS problem never
     * undoes the proforma: the shop can still share the link.
     *
     * @return array{0: Proforma, 1: SmsMessage|DomainError|null}
     */
    public function send(Invoice $draft, Tenant $tenant, User $user, array $input): array
    {
        $hours = (int) ($input['hours'] ?? self::DEFAULT_HOURS);
        if (! in_array($hours, self::HOURS, true)) {
            throw new DomainError('PROFORMA_HOURS', 'مهلت اعتبار پیش‌فاکتور را از گزینه‌ها انتخاب کنید.', 422, ['errors' => ['hours' => ['مهلت را انتخاب کنید.']]]);
        }
        // The mobile typed on the review wins (even when cleared); the draft's saved one only if none was sent.
        $rawMobile = trim((string) (array_key_exists('mobile', $input['buyer'] ?? []) ? $input['buyer']['mobile'] : $draft->buyer_mobile));
        $mobile = $rawMobile === '' ? null : Mobile::normalize($rawMobile);
        if (! $mobile || ! Mobile::isIranian($mobile)) {
            throw new DomainError('BUYER_MOBILE_REQUIRED', 'برای پیش‌فاکتور، شماره موبایل مشتری لازم است؛ مشتری با همین شماره خرید را تأیید می‌کند.', 422, ['errors' => ['buyer_mobile' => ['شماره موبایل مشتری را درست وارد کنید.']]]);
        }
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) (array_key_exists('name', $input['buyer'] ?? []) ? $input['buyer']['name'] : $draft->buyer_name))));
        $name = $name === '' ? null : mb_substr($name, 0, 80);

        $proforma = DB::transaction(function () use ($draft, $tenant, $user, $input, $hours, $mobile, $name) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $invoice = Invoice::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isDraft()) {
                throw new DomainError('NOT_DRAFT', 'این پیش‌نویس دیگر قابل ارسال نیست (قبلاً صادر یا به‌صورت پیش‌فاکتور فرستاده شده).', 409);
            }
            if ((int) ($input['version'] ?? -1) !== $invoice->version) {
                throw new DomainError('REVIEW_REQUIRED', 'پیش‌نویس پس از مرور تغییر کرد. یک بار دیگر مرور کنید.', 409, ['reason' => 'CHANGED']);
            }
            $profile = $tenant->profile()->first();
            if (! $profile?->isComplete()) {
                throw new DomainError('PROFILE_INCOMPLETE', 'برای پیش‌فاکتور، نام، موبایل و نشانی کسب‌وکار لازم است.', 409, ['missing' => $profile?->missing() ?? [], 'redirect' => route('settings.business', ['return' => $invoice->public_id])]);
            }
            [$priced, $rule] = $this->invoices->priceForIssue($invoice);

            $now = CarbonImmutable::now();
            [$jy] = Jalali::fromGregorian((int) $now->setTimezone($tenant->timezone)->format('Y'), (int) $now->setTimezone($tenant->timezone)->format('n'), (int) $now->setTimezone($tenant->timezone)->format('j'));
            $seq = (int) Proforma::query()->where('jalali_year', $jy)->max('seq') + 1;
            $number = $jy.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

            $invoice->forceFill(['buyer_name' => $name, 'buyer_mobile' => $mobile, 'payable_irr' => $priced['payable']]);
            $snapshot = $this->invoices->snapshot($invoice, $tenant, $user, $priced, $rule, ['number' => $number, 'at' => $now]);
            $invoice->forceFill(['status' => 'proforma', 'version' => $invoice->version + 1])->save();

            $p = Proforma::create([
                'invoice_id' => $invoice->id, 'jalali_year' => $jy, 'seq' => $seq, 'number' => $number,
                'token' => $t = Tokens::shareCode(), 'token_hash' => Tokens::hash($t),
                'buyer_name' => $name, 'buyer_mobile' => $mobile, 'payable_irr' => $priced['payable'], 'snapshot' => $snapshot,
                'valid_hours' => $hours, 'expires_at' => $now->addHours($hours), 'status' => 'SENT', 'sent_by' => $user->id,
                'auto_issue' => self::autoIssue($tenant),
            ]);
            SmsSetting::query()->updateOrCreate([], ['proforma_valid_hours' => $hours]);
            Audit::record('proforma.sent', $p, ['number' => $number, 'hours' => $hours, 'payable_irr' => (string) $priced['payable']]);

            return $p;
        });

        $sms = null;
        if (! empty($input['send_sms'])) {
            try {
                $sms = $this->sms->queueProformaSms($tenant, $proforma->invoice, $proforma, self::link($proforma), $user->id, 1);
            } catch (DomainError $e) {
                $sms = $e;
            }
        }

        return [$proforma, $sms];
    }

    /** «ارسال دوباره پیامک» while it is still open. */
    public function resendSms(Proforma $p, Tenant $tenant, User $user): SmsMessage
    {
        if (! $p->isOpen()) {
            throw new DomainError('PROFORMA_CLOSED', 'این پیش‌فاکتور دیگر باز نیست و پیامک فرستاده نمی‌شود.', 409);
        }
        $sent = SmsMessage::query()->where('invoice_id', $p->invoice_id)->where('purpose', 'PROFORMA')->where('idempotency_key', 'like', 'pf:'.$p->id.':%');
        $last = (clone $sent)->orderByDesc('id')->first();
        if ($last && in_array($last->status, ['QUEUED', 'SENDING', 'UNKNOWN'], true)) {
            throw new DomainError('SMS_IN_PROGRESS', 'پیامک قبلی هنوز در حال ارسال است. چند لحظه بعد دوباره ببینید.', 409);
        }
        if ($sent->count() >= 3) {
            throw new DomainError('SMS_INVOICE_LIMIT', 'برای این پیش‌فاکتور بیشتر از ۳ بار پیامک فرستاده نمی‌شود. لینک را با «اشتراک‌گذاری» بفرستید.', 429);
        }

        return $this->sms->queueProformaSms($tenant, $p->invoice, $p, self::link($p), $user->id, $sent->count() + 1);
    }

    /**
     * «ابطال پیش‌فاکتور» (also «ویرایش»): the link shows «ابطال شده» and the draft opens for editing again.
     * A confirmed one whose invoice was already issued cannot be cancelled (void the invoice instead).
     */
    public function cancel(Proforma $p, string $reason): Proforma
    {
        return DB::transaction(function () use ($p, $reason) {
            $p = Proforma::query()->whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($p->issued_at) {
                throw new DomainError('PROFORMA_ISSUED', 'فاکتور فروش این پیش‌فاکتور صادر شده است؛ برای لغو، فاکتور را باطل کنید.', 409);
            }
            if ($p->status !== 'CANCELLED') {
                $p->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancel_reason' => in_array($reason, ['EDIT', 'CUSTOMER', 'PRICE', 'OTHER'], true) ? $reason : 'OTHER']);
                Audit::record('proforma.cancelled', $p, ['reason' => $p->cancel_reason]);
            }
            $invoice = Invoice::query()->whereKey($p->invoice_id)->lockForUpdate()->first();
            if ($invoice && $invoice->status === 'proforma') {
                $invoice->forceFill(['status' => 'draft', 'version' => $invoice->version + 1])->save();
            }

            return $p;
        });
    }

    /**
     * Customer step 1: the mobile on the پیش‌فاکتور gets a confirmation code. Only that number can receive one,
     * and at most a few different numbers may be tried per پیش‌فاکتور (the page cannot probe which number it is).
     *
     * @return array{challenge_id: string, resend_after_seconds: int}
     */
    public function requestCode(Proforma $p, string $rawMobile, string $ip): array
    {
        $this->assertConfirmable($p);
        $mobile = Mobile::normalize($rawMobile);
        $key = 'pf_try:'.$p->id;
        $tried = Cache::get($key, []);
        $max = (int) config('talata.public.reveal_max_numbers', 3);
        if (! $mobile || ! hash_equals($p->buyer_mobile, $mobile)) {
            $probe = $mobile ?: 'bad:'.substr(hash('sha256', $rawMobile), 0, 12);
            if (! in_array($probe, $tried, true)) {
                $tried[] = $probe;
                Cache::put($key, $tried, $p->expires_at);
            }
            $left = max(0, $max - count($tried));
            throw new DomainError($left === 0 ? 'PROFORMA_MOBILE_LOCKED' : 'PROFORMA_MOBILE_MISMATCH', $left === 0
                ? 'شماره‌های نادرست زیادی وارد شد. برای تأیید با فروشنده تماس بگیرید.'
                : ($mobile ? 'این شماره با شماره خریدارِ این پیش‌فاکتور یکی نیست. همان شماره‌ای را بنویسید که پیامک را گرفت.' : 'شماره موبایل را درست وارد کنید (مثل ۰۹۱۲۳۴۵۶۷۸۹).'), 422, ['attempts_left' => $left]);
        }
        if (count($tried) >= $max) {
            throw new DomainError('PROFORMA_MOBILE_LOCKED', 'شماره‌های نادرست زیادی وارد شد. برای تأیید با فروشنده تماس بگیرید.', 429);
        }
        $c = $this->otp->request($mobile, $ip, 'proforma');
        Audit::record('proforma.code_requested', $p, [], $p->tenant_id, 'system');

        return ['challenge_id' => $c['challenge_id'], 'resend_after_seconds' => $c['resend_after_seconds']];
    }

    /** Customer step 2: the code confirms the پیش‌فاکتور, then the sales invoice is issued automatically. */
    public function confirm(Proforma $p, string $challengeId, string $code, string $ip): Proforma
    {
        $this->assertConfirmable($p);
        $mobile = $this->otp->verify($challengeId, $code, $ip, 'proforma');
        $p = DB::transaction(function () use ($p, $mobile) {
            $p = Proforma::withoutGlobalScope('tenant')->whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'CONFIRMED') {
                return $p; // double tap
            }
            $this->assertConfirmable($p);
            if (! hash_equals($p->buyer_mobile, $mobile)) {
                throw new DomainError('PROFORMA_MOBILE_MISMATCH', 'این کد برای شماره خریدار این پیش‌فاکتور نیست.', 422);
            }
            $p->forceFill(['status' => 'CONFIRMED', 'confirmed_at' => now()])->save();
            Audit::record('proforma.confirmed', $p, ['mobile_tail' => substr($mobile, -4)], $p->tenant_id, 'system');

            return $p;
        });
        // The choice it was sent with: automatic issuance, or the shop issues it after checking (e.g. payment).
        if ($p->auto_issue) {
            $this->issueFrom($p);
        }
        // Tell the shop: SMS to the owner and a phone notification where the web app allows it.
        try {
            NotifyProformaConfirmed::dispatch($p->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return $p->refresh();
    }

    /**
     * Issues the sales invoice of a confirmed پیش‌فاکتور (automatically after confirmation, or by the shop when
     * that failed, e.g. the monthly invoice quota was full). Same rows, rate and amount; ISSUE_ONLY (the
     * customer is on the page). A failure is recorded on the پیش‌فاکتور, never lost.
     */
    public function issueFrom(Proforma $p, ?User $by = null): ?Invoice
    {
        $p = Proforma::withoutGlobalScope('tenant')->whereKey($p->id)->firstOrFail();
        if ($p->status !== 'CONFIRMED') {
            throw new DomainError('PROFORMA_NOT_CONFIRMED', 'این پیش‌فاکتور هنوز توسط مشتری تأیید نشده است.', 409);
        }
        $tenant = Tenant::query()->findOrFail($p->tenant_id);
        $invoice = Invoice::withoutGlobalScope('tenant')->whereKey($p->invoice_id)->firstOrFail();
        if ($p->issued_at || $invoice->isIssued()) {
            return $invoice;
        }
        $user = $by ?? User::query()->find($p->sent_by)
            ?? User::query()->find(Membership::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->value('user_id'));

        return app(TenantContext::class)->runAs($tenant, function () use ($p, $tenant, $invoice, $user) {
            try {
                $result = $this->invoices->issue($invoice, $tenant, $user, [
                    'mode' => 'ISSUE_ONLY', 'version' => $invoice->version, 'idempotency_key' => 'pf-'.$p->public_id,
                    'from_proforma' => true, 'expect_payable_irr' => (string) $p->payable_irr,
                ]);
                $p->forceFill(['issued_at' => now(), 'issue_error' => null])->save();
                Audit::record('proforma.issued', $p, ['invoice' => $result['invoice']->public_id], $tenant->id, 'system');

                return $result['invoice'];
            } catch (DomainError $e) {
                $p->forceFill(['issue_error' => mb_substr($e->messageFa, 0, 250)])->save();
                Audit::record('proforma.issue_failed', $p, ['code' => $e->codeName], $tenant->id, 'system');
                report($e);

                return null;
            }
        });
    }

    private function assertConfirmable(Proforma $p): void
    {
        match ($p->state()) {
            'SENT' => null,
            'CONFIRMED' => throw new DomainError('PROFORMA_CONFIRMED', 'این پیش‌فاکتور قبلاً تأیید شده است.', 409),
            'EXPIRED' => throw new DomainError('PROFORMA_EXPIRED', 'مهلت تأیید این پیش‌فاکتور تمام شده و ابطال شده است. برای پیش‌فاکتور جدید با فروشنده تماس بگیرید.', 410),
            default => throw new DomainError('PROFORMA_CANCELLED', 'این پیش‌فاکتور توسط فروشنده ابطال شده است.', 410),
        };
    }
}
