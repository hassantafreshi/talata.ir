<?php

namespace App\Http\Controllers\App;

use App\Domain\Affiliate\AffiliateService;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PromoCodes;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Sms\SmsCredit;
use App\Models\AffiliateReferral;
use App\Models\BillingOrder;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;

class BillingController extends BaseController
{
    public function plans(CommercialConfig $config, SmsCredit $credit, PaymentGateway $gateway)
    {
        $tenant = $this->tenant();
        $vat = $config->vatRatePercent();
        $plans = [];
        foreach ($config->planCodes() as $code) {
            $p = $config->plan($code);
            $prices = [];
            foreach (['monthly', 'yearly'] as $period) {
                $sub = (string) BigInteger::of((string) $p['price_toman'][$period])->multipliedBy(10);
                $prices[$period] = ['subtotal' => $sub, 'vat' => Money::vat($sub, $vat), 'total' => (string) BigInteger::of($sub)->plus(Money::vat($sub, $vat))];
            }
            $plans[$code] = $p + ['code' => $code, 'prices' => $prices];
        }

        return view('app.plans', [
            'plans' => $plans, 'summary' => $this->ent()->summary($tenant), 'vat' => $vat, 'sms' => $config->sms(),
            'balanceFa' => Money::toman($credit->balance($tenant->id)), 'canBuy' => $this->membership()->can('billing.manage'),
            'mock' => $gateway->isMock(), 'expiring' => $credit->expiringBalance($tenant->id), 'tz' => $tenant->timezone,
            // A payment that went to the bank but has no final result yet: point at its result page instead of inviting a second payment.
            'pendingOrder' => $pending = BillingOrder::query()->where('tenant_id', $tenant->id)->whereNotIn('status', BillingOrder::FINAL)
                ->whereHas('attempts')->where('created_at', '>', now()->subDay())->latest('id')->first(),
            'pendingUrl' => $pending ? route('pay.result', [$pending->public_id, 's' => app(BillingService::class)->resultSignature($pending)]) : null,
            // Prefill: ?code= from a referral link, the link cookie, or the shop's existing referral.
            'prefillCode' => AffiliateService::normalizeCode(request()->query('code') ?? request()->cookie('talata_ref'))
                ?? AffiliateReferral::query()->where('tenant_id', $tenant->id)->with('affiliate')->first()?->affiliate?->code,
        ]);
    }

    public function sms(Request $request, CommercialConfig $config, SmsCredit $credit, PaymentGateway $gateway)
    {
        $tenant = $this->tenant();
        $plan = $this->ent()->planCode($tenant);
        $sms = $config->sms();
        $min = (string) $sms['min_purchase_toman'][$plan];
        $packs = array_values(array_filter($sms['pack_amounts_toman'], fn ($p) => ! BigInteger::of($p)->isLessThan($min)));
        if (! in_array($min, $packs, true)) {
            array_unshift($packs, $min);
        }
        $perSegment = $this->ent()->smsPerSegmentIrr($tenant);
        $orders = BillingOrder::query()->where('product', 'SMS_CREDIT')->latest('id')->limit(5)->get();

        return view('app.sms-buy', [
            'packs' => $packs, 'perSegmentIrr' => $perSegment, 'balanceIrr' => $credit->balance($tenant->id), 'plan' => $this->ent()->plan($tenant),
            'carries' => (bool) $sms['carry_over'][$plan], 'vat' => $config->vatRatePercent(), 'minToman' => $min, 'mock' => $gateway->isMock(),
            'free' => $this->ent()->freeSmsRemaining($tenant), 'freeYear' => (int) ($this->ent()->plan($tenant)['quotas']['free_sms_per_year'] ?? 0),
            'returnId' => preg_match('/^[0-9a-z]{26}$/', (string) $request->query('return')) ? $request->query('return') : null,
            'orders' => $orders, 'tz' => $tenant->timezone, 'canBuy' => $this->membership()->can('billing.manage'), 'sms' => $sms, 'planCode' => $plan,
        ]);
    }

    public function createOrder(Request $request, BillingService $billing)
    {
        $data = $request->validate([
            'product' => ['required', 'in:PLAN,SMS_CREDIT'], 'plan' => ['nullable', 'string', 'max:20'], 'period' => ['nullable', 'string', 'max:10'],
            'pack_amount_toman' => ['nullable', 'string', 'max:20'], 'return_to' => ['nullable', 'array'], 'idempotency_key' => ['required', 'string', 'max:64'],
            'discount_code' => ['nullable', 'string', 'max:30'],
        ]);
        $result = $billing->createOrder($this->tenant(), $request->user(), $data);

        return response()->json(['order_id' => $result['order']->public_id, 'redirect' => $result['redirect']], 201);
    }

    /** Live price with an affiliate discount code (display only; the order is priced again on the server). */
    public function discount(Request $request, AffiliateService $affiliates, CommercialConfig $config, PromoCodes $promos)
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:30'], 'plan' => ['required', 'in:basic,professional'], 'period' => ['required', 'in:monthly,yearly']]);
        $tenant = $this->tenant();
        $list = (string) BigInteger::of((string) $config->plan($data['plan'])['price_toman'][$data['period']])->multipliedBy(10);
        if ($promo = $promos->find($data['code'] ?? null)) {
            $discount = $promos->discount($promo, $tenant, 'PLAN', $list);
            $sub = (string) BigInteger::of($list)->minus($discount);
            $vat = Money::vat($sub, $config->vatRatePercent());

            return response()->json([
                'code' => $promo->code, 'applied' => true,
                'message_fa' => 'کد '.$promo->code.' اعمال شد: '.Digits::percent($promo->percent).'٪ تخفیف.'.($sub === '0' ? ' مبلغی برای پرداخت نمی‌ماند و بدون رفتن به بانک فعال می‌شود.' : ''),
                'list_fa' => Money::toman($list), 'discount_fa' => Money::toman($discount), 'subtotal_fa' => Money::toman($sub),
                'vat_fa' => Money::toman($vat), 'total_fa' => Money::toman((string) BigInteger::of($sub)->plus($vat)),
            ]);
        }
        [$affiliate, $discount, $code] = $affiliates->quote($tenant, 'PLAN', $list, $data['code'] ?? null);
        $sub = (string) BigInteger::of($list)->minus($discount);
        $vat = Money::vat($sub, $config->vatRatePercent());

        return response()->json([
            'code' => $code, 'applied' => $discount !== '0',
            'message_fa' => $discount !== '0' ? 'کد '.$code.' اعمال شد: '.Digits::percent($affiliate->discount_percent).'٪ تخفیف روی خرید اول پلن.'
                : ($code ? 'کد معرف ثبت شد؛ تخفیف فقط روی خرید اول پلن است.' : null),
            'list_fa' => Money::toman($list), 'discount_fa' => Money::toman($discount), 'subtotal_fa' => Money::toman($sub),
            'vat_fa' => Money::toman($vat), 'total_fa' => Money::toman((string) BigInteger::of($sub)->plus($vat)),
        ]);
    }

    public function order(BillingOrder $order)
    {
        return response()->json(['status' => $order->status]);
    }

    public function receipt(BillingOrder $order, PaymentGateway $gateway)
    {
        abort_unless(in_array($order->status, ['FULFILLED', 'PAID'], true), 404);

        $attempt = $order->attempts()->latest('id')->first();

        // «آزمایشی» depends on the gateway that took this payment, not on today's default.
        return view('print.receipt', ['order' => $order, 'attempt' => $attempt, 'tz' => $this->tenant()->timezone, 'shop' => $this->tenant()->profile, 'mock' => ($attempt?->gateway ?? $gateway->code()) === 'mock']);
    }
}
