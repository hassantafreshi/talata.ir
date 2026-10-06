<?php

namespace App\Domain\Billing;

use App\Domain\Affiliate\AffiliateService;
use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsCredit;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\StaffUser;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\TechLog;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan purchase and SMS top-up (docs/PAYMENTS_AND_SMS_CREDIT.md).
 * Amounts come only from server configuration (+10% VAT); the bank callback is verified
 * server-to-server with the stored amount; fulfilment runs once under row locks.
 */
final class BillingService
{
    public const BANK_CODES_FA = [
        '17' => 'لغو توسط کاربر در درگاه',
        '51' => 'موجودی ناکافی',
        '-11' => 'پرداخت تأیید نشد',
        'AMOUNT_MISMATCH' => 'مبلغ تأییدشده بانک با سفارش یکسان نبود',
        'EXPIRED' => 'مهلت پرداخت تمام شد',
        'MANUAL_FAILED' => 'ناموفق به تشخیص مالی (پرداختی انجام نشده بود)',
    ] + Gateways\ZarinpalGateway::CODES_FA;

    public function __construct(
        private readonly PaymentGateways $gateways,
        private readonly CommercialConfig $config,
        private readonly Entitlements $entitlements,
        private readonly SmsCredit $credit,
        private readonly AffiliateService $affiliates,
    ) {}

    /** @return array{order:BillingOrder,redirect:array} */
    public function createOrder(Tenant $tenant, User $user, array $input): array
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        if (! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            throw new DomainError('IDEMPOTENCY_KEY', 'درخواست نامعتبر است. صفحه را دوباره باز کنید.', 422);
        }
        $existing = BillingOrder::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            $attempt = $existing->attempts()->latest('id')->first();
            $gw = $attempt ? $this->gateways->for($attempt->gateway) : null;
            // Same request again (double tap / retry): back to the same bank page while it is still open.
            if ($gw && $existing->status === 'AWAITING_PAYMENT' && now()->lt($existing->expires_at)) {
                $r = $gw->redirectFor($attempt->authority);

                return ['order' => $existing, 'redirect' => ['url' => $r['redirect_url'], 'method' => $r['method'], 'fields' => $r['fields']]];
            }

            return ['order' => $existing, 'redirect' => ['url' => route('pay.result', [$existing->public_id, 's' => $this->resultSignature($existing)]), 'method' => 'GET', 'fields' => []]];
        }

        $product = $input['product'] ?? '';
        $currentPlan = $this->entitlements->planCode($tenant);
        $snapshot = ['pricing_version' => $this->config->version(), 'plan_at_order' => $currentPlan];
        if ($product === 'PLAN') {
            $planCode = $input['plan'] ?? '';
            $period = $input['period'] ?? '';
            if (! in_array($planCode, ['basic', 'professional'], true) || ! in_array($period, ['monthly', 'yearly'], true)) {
                throw new DomainError('PLAN_INVALID', 'پلن یا دوره انتخاب‌شده معتبر نیست.', 422);
            }
            $order = ['plan_code' => $planCode, 'period' => $period];
            $snapshot['plan_label_fa'] = $this->config->plan($planCode)['label_fa'];
            $subtotalToman = (string) $this->config->plan($planCode)['price_toman'][$period];
        } elseif ($product === 'SMS_CREDIT') {
            $sms = $this->config->sms();
            $pack = preg_replace('/\D/', '', (string) ($input['pack_amount_toman'] ?? ''));
            $min = (string) $sms['min_purchase_toman'][$currentPlan];
            // Only fixed packs (plus the plan minimum itself): no arbitrary amounts.
            if (! in_array($pack, $sms['pack_amounts_toman'], true) && $pack !== $min) {
                throw new DomainError('PACK_INVALID', 'مبلغ شارژ باید یکی از بسته‌های تعریف‌شده باشد.', 422);
            }
            if (BigInteger::of($pack)->isLessThan($min)) {
                throw new DomainError('BELOW_MINIMUM', 'حداقل شارژ برای پلن فعلی '.Money::toman((string) BigInteger::of($min)->multipliedBy(10)).' تومان است.', 422);
            }
            $order = ['plan_code' => null, 'period' => null];
            $subtotalToman = $pack;
            $snapshot['per_segment_toman'] = $sms['per_segment_toman'][$currentPlan];
            $snapshot['carries_over'] = (bool) $sms['carry_over'][$currentPlan];
        } else {
            throw new DomainError('PRODUCT_INVALID', 'محصول نامعتبر است.', 422);
        }

        $listSubtotal = (string) BigInteger::of($subtotalToman)->multipliedBy(10);
        // Affiliate discount (first plan purchase of a referred shop) comes off the pre-VAT price.
        [$affiliate, $discount, $code] = $this->affiliates->quote($tenant, $product, $listSubtotal, $input['discount_code'] ?? null);
        $subtotal = (string) BigInteger::of($listSubtotal)->minus($discount);
        if ($discount !== '0') {
            $snapshot['discount'] = ['code' => $code, 'percent' => $affiliate->discount_percent, 'irr' => $discount];
        }
        $affiliateFields = ['list_subtotal_irr' => $listSubtotal, 'discount_irr' => $discount, 'affiliate_id' => $affiliate?->id, 'discount_code' => $code];
        $vatRate = $this->config->vatRatePercent();
        $vat = Money::vat($subtotal, $vatRate);
        $amount = (string) BigInteger::of($subtotal)->plus($vat);
        $returnTo = $this->sanitizeReturnTo($input['return_to'] ?? null);

        return DB::transaction(function () use ($tenant, $user, $product, $order, $subtotal, $vatRate, $vat, $amount, $snapshot, $returnTo, $key, $affiliateFields) {
            $billing = BillingOrder::create($order + $affiliateFields + [
                'public_ref' => 'TL-'.($product === 'PLAN' ? 'PLAN' : 'SMS').'-'.Jalali::year(now(), $tenant->timezone).'-'.strtoupper(Str::random(6)),
                'created_by' => $user->id, 'product' => $product, 'subtotal_irr' => $subtotal, 'vat_rate_percent' => $vatRate,
                'vat_irr' => $vat, 'amount_irr' => $amount, 'price_snapshot' => $snapshot, 'return_to' => $returnTo,
                'status' => 'AWAITING_PAYMENT', 'idempotency_key' => $key, 'expires_at' => now()->addMinutes(config('talata.payments.order_expiry_minutes')),
            ]);
            $gateway = $this->gateways->default();
            try {
                $redirect = $gateway->request($billing->public_ref, $amount, route('pay.callback', $gateway->code()), $user->mobile);
            } catch (PaymentGatewayError $e) {
                // Nothing was charged; the transaction rolls the order back.
                throw new DomainError('GATEWAY_UNAVAILABLE', 'درگاه پرداخت الان پاسخ نمی‌دهد. چند دقیقه بعد دوباره امتحان کنید؛ مبلغی کسر نشده است.', 503, ['psp_code' => $e->pspCode]);
            }
            PaymentAttempt::create([
                'order_id' => $billing->id, 'gateway' => $gateway->code(), 'authority' => $redirect['authority'],
                'amount_irr' => $amount, 'status' => 'AWAITING_PAYMENT',
            ]);
            Audit::record('billing.order_created', $billing, ['product' => $product, 'amount_irr' => $amount]);

            return ['order' => $billing, 'redirect' => ['url' => $redirect['redirect_url'], 'method' => $redirect['method'], 'fields' => $redirect['fields']]];
        });
    }

    private function sanitizeReturnTo(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $route = $value['route'] ?? null;
        $id = preg_match('/^[0-9a-z]{26}$/', (string) ($value['id'] ?? '')) ? $value['id'] : null;

        return match ($route) {
            'review' => $id ? ['route' => 'review', 'id' => $id] : null,
            'invoice' => $id ? ['route' => 'invoice', 'id' => $id] : null,
            'customers', 'settings', 'plans' => ['route' => $route],
            default => null,
        };
    }

    /** Bank callback (no session). Returns the order for redirect. Idempotent. */
    public function handleCallback(string $gatewayCode, Request $request): ?BillingOrder
    {
        // The adapter that opened the payment answers for it, even after the default PSP changed.
        $gateway = $this->gateways->for($gatewayCode);
        if (! $gateway) {
            return null;
        }
        $cb = $gateway->parseCallback($request);
        TechLog::info('payments', 'gateway callback', ['gateway' => $gatewayCode, 'status' => $cb['status'] ?? null, 'has_authority' => (bool) ($cb['authority'] ?? null), 'ip' => $request->ip()]);
        if (! $cb['authority']) {
            return null;
        }
        $attempt = PaymentAttempt::query()->where('gateway', $gatewayCode)->where('authority', $cb['authority'])->first();
        if (! $attempt) {
            return null;
        }

        DB::transaction(function () use ($attempt, $cb) {
            $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($attempt->order_id)->lockForUpdate()->first();
            $attempt = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            // A non-OK callback is unauthenticated (anyone who saw the bank URL can send it), so a FAILED
            // order stays reopenable by a later callback until it expires; the gateway verify decides.
            $reopenable = $order->status === 'FAILED' && $order->failure_code !== 'AMOUNT_MISMATCH'
                && $attempt->status !== 'PAID' && now()->lt($order->expires_at);
            if (($order->isFinal() && ! $reopenable) || $order->status === 'PAID') {
                return;
            }
            $attempt->callback_at = now();
            $attempt->raw_result_redacted = $cb['raw'];
            if ($cb['status'] !== 'OK') {
                $this->fail($order, $attempt, $cb['bank_code'] ?? '17');

                return;
            }
            $this->verifyAndFulfil($order, $attempt);
        });

        return BillingOrder::withoutGlobalScope('tenant')->find($attempt->order_id);
    }

    /** Caller holds row locks on order and attempt. */
    private function verifyAndFulfil(BillingOrder $order, PaymentAttempt $attempt): void
    {
        $order->status = 'VERIFYING';
        $order->save();
        $this->applyVerification($order, $attempt, $this->askGateway($order, $attempt));
    }

    /** Server-to-server verify with the stored amount through the adapter that opened the payment. Never throws. */
    private function askGateway(BillingOrder $order, PaymentAttempt $attempt): array
    {
        try {
            $gateway = $this->gateways->for($attempt->gateway) ?? throw new \RuntimeException("gateway [{$attempt->gateway}] is not registered");

            return $gateway->verify($attempt->authority, $attempt->amount_irr);
        } catch (\Throwable $e) {
            // Network/PSP error: never lose a possibly-paid order; reconcile retries with backoff.
            TechLog::error('payments', 'gateway verify error', ['gateway' => $attempt->gateway, 'order' => $order->public_ref, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['status' => 'UNKNOWN', 'bank_code' => null];
        }
    }

    /** Caller holds row locks on order and attempt. */
    private function applyVerification(BillingOrder $order, PaymentAttempt $attempt, array $v): void
    {
        $attempt->bank_code = $v['bank_code'] ?? null;
        TechLog::info('payments', 'gateway verify', ['gateway' => $attempt->gateway, 'order' => $order->public_ref, 'status' => $v['status'], 'bank_code' => $v['bank_code'] ?? null]);
        if ($v['status'] === 'UNKNOWN') {
            $order->status = 'PENDING_VERIFICATION';
            $attempt->status = 'PENDING_VERIFICATION';
            $attempt->next_reconcile_at = now()->addMinute();
            $order->save();
            $attempt->save();

            return;
        }
        if ($v['status'] !== 'OK') {
            $this->fail($order, $attempt, $v['bank_code'] ?? '-11');

            return;
        }
        if ((string) $v['amount_irr'] !== (string) $order->amount_irr) {
            $this->fail($order, $attempt, 'AMOUNT_MISMATCH');
            Audit::record('billing.amount_mismatch', $order, ['order_irr' => $order->amount_irr, 'bank_irr' => $v['amount_irr']], $order->tenant_id, 'system');

            return;
        }
        $attempt->status = 'PAID';
        $attempt->ref_id = $v['ref_id'];
        $attempt->card_mask = $v['card_mask'];
        $attempt->verified_at = now();
        $attempt->save();
        $order->status = 'PAID';
        $order->paid_at = now();
        $order->save();
        try {
            DB::transaction(fn () => $this->fulfil($order)); // savepoint: a failure keeps PAID
        } catch (\Throwable $e) {
            report($e);
            $order->refresh(); // reconcile() fulfils PAID orders later
        }
    }

    private function fail(BillingOrder $order, PaymentAttempt $attempt, string $code): void
    {
        $attempt->status = 'FAILED';
        $attempt->bank_code = $code;
        $attempt->save();
        $order->status = 'FAILED';
        $order->failure_code = $code;
        $order->failure_message = self::BANK_CODES_FA[$code] ?? 'پرداخت تأیید نشد';
        $order->save();
        Audit::record('billing.failed', $order, ['code' => $code], $order->tenant_id, 'system');
    }

    /** Applies the purchase exactly once (status check under the order row lock). */
    private function fulfil(BillingOrder $order): void
    {
        if ($order->status !== 'PAID') {
            return;
        }
        $tenant = Tenant::query()->lockForUpdate()->find($order->tenant_id);
        if ($order->product === 'PLAN') {
            $this->activatePlan($tenant, $order);
        } else {
            $this->credit->creditFromOrder($order, $order->price_snapshot['plan_at_order'], (bool) $order->price_snapshot['carries_over'], $tenant);
        }
        $order->status = 'FULFILLED';
        $order->fulfilled_at = now();
        $order->save();
        Audit::record('billing.fulfilled', $order, ['product' => $order->product, 'amount_irr' => $order->amount_irr], $order->tenant_id, 'system');
        try {
            // Savepoint: an affiliate problem must never undo the merchant's paid plan or credit.
            DB::transaction(fn () => $this->affiliates->onOrderFulfilled($order, $tenant));
        } catch (\Throwable $e) {
            TechLog::error('affiliate', 'commission not recorded', ['order' => $order->public_ref, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    private function activatePlan(Tenant $tenant, BillingOrder $order): void
    {
        $current = Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('status', 'active')
            ->where('ends_at', '>', now())->orderByDesc('ends_at')->lockForUpdate()->first();
        $start = now();
        $carry = 0;
        if ($current) {
            if ($current->plan_code === $order->plan_code) {
                $start = $current->ends_at; // renewal extends
            } else {
                $carry = $this->prorateDays($current, $order);
                $current->update(['status' => 'superseded', 'ends_at' => now()]);
            }
        }
        $ends = ($order->period === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth())->addDays($carry);
        Subscription::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id, 'plan_code' => $order->plan_code, 'period' => $order->period, 'starts_at' => now(),
            'ends_at' => $ends, 'carry_over_days' => $carry, 'activated_by' => $order->channel === 'MANUAL' ? 'PROVIDER' : 'PAYMENT',
            'source_order_id' => $order->id, 'status' => 'active',
        ]);
        app(CommercialConfig::class)->forget();
    }

    /**
     * Plan change: what is left of the current plan is converted by value, not day for day — remaining days ×
     * its daily list price ÷ the new plan's daily list price (pre-VAT, current pricing version). Day for day
     * would turn a cheap yearly plan into months of an expensive one. Policy PRORATE_BY_VALUE_V1, documented
     * in docs/PAYMENTS_AND_SMS_CREDIT.md (owner may still change it).
     */
    private function prorateDays(Subscription $current, BillingOrder $order): int
    {
        $remaining = max(0, $current->ends_at->getTimestamp() - now()->getTimestamp());
        $daily = function (?string $plan, ?string $period): BigRational {
            $toman = (string) ($plan && $period ? ($this->config->plan($plan)['price_toman'][$period] ?? '0') : '0');

            return BigRational::of($toman === '' ? '0' : $toman)->dividedBy($period === 'yearly' ? 365 : 30);
        };
        $old = $daily($current->plan_code, $current->period);
        $new = $daily($order->plan_code, $order->period);
        if ($remaining === 0 || $old->isZero() || $new->isZero()) {
            return 0;
        }

        return (int) BigRational::of($remaining)->dividedBy(86400)->multipliedBy($old)->dividedBy($new)->toScale(0, RoundingMode::Down)->toInt();
    }

    /** Scheduler: retries ambiguous verifications, expires abandoned orders. */
    public function reconcile(): array
    {
        $done = ['reconciled' => 0, 'expired' => 0];
        PaymentAttempt::query()->where('status', 'PENDING_VERIFICATION')->where('next_reconcile_at', '<=', now())->orderBy('id')->limit(100)->get()
            ->each(function (PaymentAttempt $a) use (&$done) {
                DB::transaction(function () use ($a, &$done) {
                    $attempt = PaymentAttempt::query()->whereKey($a->id)->lockForUpdate()->first();
                    $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($attempt->order_id)->lockForUpdate()->first();
                    if ($order->isFinal()) {
                        return;
                    }
                    $attempt->reconcile_attempts++;
                    if ($order->created_at->lt(now()->subHours(config('talata.payments.reconcile_max_hours')))) {
                        $this->fail($order, $attempt, '-11');

                        return;
                    }
                    $this->verifyAndFulfil($order, $attempt);
                    if ($order->status === 'PENDING_VERIFICATION') {
                        $attempt->next_reconcile_at = now()->addMinutes(min(60, 2 ** min(6, $attempt->reconcile_attempts)));
                        $attempt->save();
                    }
                    $done['reconciled']++;
                });
            });
        // Paid but not yet fulfilled (fulfilment failed earlier): finish it, idempotently.
        BillingOrder::withoutGlobalScope('tenant')->where('status', 'PAID')->where('paid_at', '<', now()->subMinute())->orderBy('id')->limit(100)->get()
            ->each(function (BillingOrder $o) use (&$done) {
                DB::transaction(function () use ($o, &$done) {
                    $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($o->id)->lockForUpdate()->first();
                    $this->fulfil($order);
                    $done['reconciled']++;
                });
            });
        BillingOrder::withoutGlobalScope('tenant')->where('status', 'AWAITING_PAYMENT')->where('expires_at', '<', now())->orderBy('id')->limit(100)->get()
            ->each(function (BillingOrder $o) use (&$done) {
                DB::transaction(function () use ($o, &$done) {
                    $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($o->id)->lockForUpdate()->first();
                    if ($order->status !== 'AWAITING_PAYMENT') {
                        return;
                    }
                    $attempt = PaymentAttempt::query()->where('order_id', $order->id)->latest('id')->lockForUpdate()->first();
                    // If the bank already called back, or the payer may have paid and closed the browser before
                    // returning, ask the PSP before giving up on the order (verify is idempotent and amount-checked).
                    if ($attempt && $order->expires_at->gt(now()->subHours(config('talata.payments.reconcile_max_hours')))) {
                        $v = $this->askGateway($order, $attempt);
                        if ($v['status'] === 'OK' || $v['status'] === 'UNKNOWN' || $attempt->callback_at) {
                            $order->status = 'VERIFYING';
                            $this->applyVerification($order, $attempt, $v);
                            $done['reconciled']++;

                            return;
                        }
                        $attempt->update(['status' => 'FAILED', 'bank_code' => $v['bank_code'] ?? null]);
                    }
                    $order->update(['status' => 'EXPIRED', 'failure_code' => 'EXPIRED', 'failure_message' => self::BANK_CODES_FA['EXPIRED']]);
                    $done['expired']++;
                });
            });

        return $done;
    }

    /**
     * Finance records a plan paid outside the gateway (bank transfer, POS, card-to-card). It becomes an
     * order on the MANUAL channel and then runs the same fulfilment as an online payment (subscription
     * activated_by=PROVIDER; any affiliate commission on the actual base). The received amount (VAT
     * included) is split back into base + VAT for the finance export; 0 = complimentary.
     */
    public function manualActivation(Tenant $tenant, StaffUser $staff, string $planCode, string $period, string $receivedIrr, string $reference, string $reason): BillingOrder
    {
        if (! in_array($planCode, ['basic', 'professional'], true) || ! in_array($period, ['monthly', 'yearly'], true)) {
            throw new DomainError('PLAN_INVALID', 'پلن یا دوره انتخاب‌شده معتبر نیست.', 422);
        }
        $vatRate = $this->config->vatRatePercent();
        $list = (string) BigInteger::of((string) $this->config->plan($planCode)['price_toman'][$period])->multipliedBy(10);
        $base = (string) BigDecimal::of($receivedIrr)->multipliedBy(100)->dividedBy(BigDecimal::of(100)->plus($vatRate), 0, RoundingMode::HalfUp);

        return DB::transaction(function () use ($tenant, $staff, $planCode, $period, $receivedIrr, $reference, $reason, $vatRate, $list, $base) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $order = BillingOrder::create([
                'tenant_id' => $tenant->id, 'public_ref' => 'TL-PLAN-'.Jalali::year(now(), $tenant->timezone).'-'.strtoupper(Str::random(6)),
                'created_by' => null, 'product' => 'PLAN', 'plan_code' => $planCode, 'period' => $period,
                'list_subtotal_irr' => $list, 'discount_irr' => '0', 'subtotal_irr' => $base, 'vat_rate_percent' => $vatRate,
                'vat_irr' => (string) BigInteger::of($receivedIrr)->minus($base), 'amount_irr' => $receivedIrr,
                'price_snapshot' => [
                    'pricing_version' => $this->config->version(), 'plan_at_order' => $this->entitlements->planCode($tenant),
                    'plan_label_fa' => $this->config->plan($planCode)['label_fa'],
                    'list_amount_irr' => (string) BigInteger::of($list)->plus(Money::vat($list, $vatRate)),
                ],
                'status' => 'PAID', 'paid_at' => now(), 'idempotency_key' => 'manual-'.strtolower((string) Str::ulid()), 'expires_at' => now(),
                'channel' => 'MANUAL', 'staff_id' => $staff->id, 'staff_reason' => $reason, 'manual_reference' => $reference,
            ]);
            PaymentAttempt::create([
                'order_id' => $order->id, 'gateway' => 'manual', 'authority' => 'M'.strtolower((string) Str::ulid()), 'amount_irr' => $receivedIrr,
                'status' => 'PAID', 'ref_id' => $reference, 'verified_at' => now(),
            ]);
            Audit::record('billing.manual_activation', $order, [
                'plan' => $planCode, 'period' => $period, 'received_irr' => $receivedIrr, 'reference' => $reference, 'reason' => $reason,
            ], $tenant->id, 'staff');
            $this->fulfil($order);

            return $order->fresh();
        });
    }

    /**
     * Finance confirms a payment that the bank statement shows but the gateway never confirmed
     * (verify kept failing, payer never returned, order expired). Runs the normal idempotent fulfilment.
     */
    public function manualConfirm(BillingOrder $order, StaffUser $staff, string $bankReference, string $reason): BillingOrder
    {
        return DB::transaction(function () use ($order, $staff, $bankReference, $reason) {
            $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'FULFILLED') {
                throw new DomainError('ORDER_ALREADY_FULFILLED', 'این سفارش قبلاً انجام شده است.', 409);
            }
            $before = $order->status;
            if ($order->status !== 'PAID') {
                $attempt = PaymentAttempt::query()->where('order_id', $order->id)->latest('id')->lockForUpdate()->first();
                $attempt?->forceFill(['status' => 'PAID', 'ref_id' => $attempt->ref_id ?? $bankReference, 'verified_at' => $attempt->verified_at ?? now()])->save();
                $order->forceFill(['status' => 'PAID', 'paid_at' => now(), 'failure_code' => null, 'failure_message' => null]);
            }
            $order->forceFill(['staff_id' => $staff->id, 'staff_reason' => $reason, 'manual_reference' => $bankReference])->save();
            Audit::record('billing.manual_confirm', $order, ['before' => $before, 'reference' => $bankReference, 'reason' => $reason], $order->tenant_id, 'staff');
            $this->fulfil($order);

            return $order->fresh();
        });
    }

    /** Finance closes an open order the payer did not pay. A later genuine bank confirmation still reopens it. */
    public function markFailed(BillingOrder $order, StaffUser $staff, string $reason): BillingOrder
    {
        return DB::transaction(function () use ($order, $staff, $reason) {
            $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['AWAITING_PAYMENT', 'VERIFYING', 'PENDING_VERIFICATION'], true)) {
                throw new DomainError('ORDER_NOT_OPEN', 'فقط سفارش باز یا در انتظار بررسی را می‌توان ناموفق ثبت کرد.', 409);
            }
            $attempt = PaymentAttempt::query()->where('order_id', $order->id)->latest('id')->lockForUpdate()->firstOrFail();
            $order->forceFill(['staff_id' => $staff->id, 'staff_reason' => $reason]);
            $this->fail($order, $attempt, 'MANUAL_FAILED');
            Audit::record('billing.manual_failed', $order, ['reason' => $reason], $order->tenant_id, 'staff');

            return $order->fresh();
        });
    }

    /**
     * «استعلام دوباره از بانک»: asks the PSP that opened the payment. Open orders follow the answer;
     * a FAILED/EXPIRED order is fulfilled only when the bank confirms the stored amount was paid.
     *
     * @return array{result:string,bank_code:?string,status:string}
     */
    public function inquire(BillingOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            $order = BillingOrder::withoutGlobalScope('tenant')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::query()->where('order_id', $order->id)->latest('id')->lockForUpdate()->first();
            if (! $attempt || $attempt->gateway === 'manual') {
                throw new DomainError('NO_GATEWAY_PAYMENT', 'این سفارش از درگاه پرداخت نشده است؛ استعلام بانکی ندارد.', 422);
            }
            $before = $order->status;
            $v = $this->askGateway($order, $attempt);
            if ($order->status === 'PAID') {
                DB::transaction(fn () => $this->fulfil($order));
            } elseif ($order->status !== 'FULFILLED' && ($v['status'] === 'OK' || ! $order->isFinal())) {
                $order->status = 'VERIFYING';
                $this->applyVerification($order, $attempt, $v);
            }
            Audit::record('billing.inquired', $order, ['result' => $v['status'], 'bank_code' => $v['bank_code'] ?? null, 'before' => $before, 'after' => $order->status], $order->tenant_id, 'staff');

            return ['result' => $v['status'], 'bank_code' => $v['bank_code'] ?? null, 'status' => $order->status];
        });
    }

    public function resultSignature(BillingOrder $order): string
    {
        return substr(hash_hmac('sha256', 'pay-result|'.$order->public_id, (string) config('app.key')), 0, 32);
    }
}
