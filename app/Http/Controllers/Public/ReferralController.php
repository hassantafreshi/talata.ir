<?php

namespace App\Http\Controllers\Public;

use App\Domain\Affiliate\AffiliateService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Referral link: remembers the affiliate code for signup and prefills it at checkout. */
class ReferralController extends Controller
{
    public function __invoke(Request $request, string $code, AffiliateService $affiliates)
    {
        $affiliate = $affiliates->findActive($code);
        if (! $affiliate) {
            return redirect()->route('login');
        }
        Cookie::queue('talata_ref', $affiliate->code, config('talata.affiliate.link_cookie_days') * 24 * 60, '/', null, null, true, false, 'lax');

        return $request->user() ? redirect()->route('settings.plan', ['code' => $affiliate->code]) : redirect()->route('login');
    }
}
