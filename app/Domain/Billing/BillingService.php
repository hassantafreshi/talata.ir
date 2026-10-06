<?php

namespace App\Domain\Billing;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsCredit;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Brick\Math\BigInteger;
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
    ];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CommercialConfig $config,
        private readonly Entitlements $entitlements,
        private readonly SmsCredit $credit,
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

            return ['order' => $existing, 'redirect' => ['url' => $this->gateway->isMock() ? route('pay.mock', $attempt->authority) : route('pay.result', $existing), 'method' => 'GET', 'fields' => []]];
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

        $subtotal = (string) BigInteger::of($subtotalToman)->multipliedBy(10);
        $vatRate = $this->config->vatRatePercent();
        $vat = Money::vat($subtotal, $vatRate);
        $amount = (string) BigInteger::of($subtotal)->plus($vat);
        $returnTo = $this->sanitizeReturnTo($input['return_to'] ?? null);

        return DB::transaction(function () use ($tenant, $user, $product, $order, $subtotal, $vatRate, $vat, $amount, $snapshot, $returnTo, $key) {
            $billing = BillingOrder::create($order + [
                'public_ref' => 'TL-'.($product === 'PLAN' ? 'PLAN' : 'SMS').'-'.Jalali::year(now(), $tenant->timezone).'-'.strtoupper(Str::random(6)),
                'created_by' => $user->id, 'product' => $product, 'subtotal_irr' => $subtotal, 'vat_rate_percent' => $vatRate,
                'vat_irr' => $vat, 'amount_irr' => $amount, 'price_snapshot' => $snapshot, 'return_to' => $returnTo,
                'status' => 'AWAITING_PAYMENT', 'idempotency_key' => $key, 'expires_at' => now()->addMinutes(config('talata.payments.order_expiry_minutes')),
            ]);
            $redirect = $this->gateway->request($billing->public_ref, $amount, route('pay.callback', $this->gateway->code()), $user->mobile);
            PaymentAttempt::create([
                'order_id' => $billing->id, 'gateway' => $this->gateway->code(), 'authority' => $redirect['authority'],
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
        if ($gatewayCode !== $this->gateway->code()) {
            return null;
        }
        $cb = $this->gateway->parseCallback($request);
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
            if ($order->isFinal() || in_array($order->status, ['PAID'], true)) {
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
        $v = $this->gateway->verify($attempt->authority, $attempt->amount_irr);
        $attempt->bank_code = $v['bank_code'];
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
        $this->fulfil($order);
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
                $carry = (int) max(0, now()->diffInDays($current->ends_at)); // proration: ADD_REMAINING_DAYS (owner-pending policy)
                $current->update(['status' => 'superseded', 'ends_at' => now()]);
            }
        }
        $ends = ($order->period === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth())->addDays($carry);
        Subscription::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id, 'plan_code' => $order->plan_code, 'period' => $order->period, 'starts_at' => now(),
            'ends_at' => $ends, 'carry_over_days' => $carry, 'activated_by' => 'PAYMENT', 'source_order_id' => $order->id, 'status' => 'active',
        ]);
        app(CommercialConfig::class)->forget();
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
        BillingOrder::withoutGlobalScope('tenant')->where('status', 'AWAITING_PAYMENT')->where('expires_at', '<', now())->orderBy('id')->limit(500)->get()
            ->each(function (BillingOrder $o) use (&$done) {
                $o->update(['status' => 'EXPIRED', 'failure_code' => 'EXPIRED', 'failure_message' => self::BANK_CODES_FA['EXPIRED']]);
                $done['expired']++;
            });

        return $done;
    }

    public function resultSignature(BillingOrder $order): string
    {
        return substr(hash_hmac('sha256', 'pay-result|'.$order->public_id, (string) config('app.key')), 0, 32);
    }
}
