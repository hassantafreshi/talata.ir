<?php

namespace App\Http\Controllers\App;

use App\Domain\Affiliate\AffiliateService;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;

/**
 * The affiliate's own dashboard. Per person (the logged-in mobile), not per shop.
 * Buyers are shown only as "first three … last three" digits of their mobile; no shop names.
 */
class AffiliateController extends BaseController
{
    public function show(AffiliateService $affiliates)
    {
        $affiliate = Affiliate::query()->where('user_id', auth()->id())->firstOrFail();
        $referrals = AffiliateReferral::query()->where('affiliate_id', $affiliate->id)->orderByDesc('attributed_at')->limit(200)->get();
        $income = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->where('status', '!=', 'VOID')
            ->selectRaw('referral_id, sum(amount_irr) s, count(*) c')->groupBy('referral_id')->get()->keyBy('referral_id');

        return view('app.affiliate', [
            'affiliate' => $affiliate,
            'totals' => $affiliates->totals($affiliate),
            'buyers' => $referrals->map(fn ($r) => [
                'mask' => AffiliateService::maskBuyer($r->buyer_mobile), 'since' => $r->attributed_at, 'source' => $r->source,
                'income' => (string) ($income[$r->id]->s ?? '0'), 'payments' => (int) ($income[$r->id]->c ?? 0),
            ]),
            'entries' => AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->with('referral')->orderByDesc('id')->limit(100)->get(),
            'payouts' => AffiliatePayout::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
