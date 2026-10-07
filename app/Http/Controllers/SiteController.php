<?php

namespace App\Http\Controllers;

use App\Domain\Plans\CommercialConfig;
use App\Models\PlatformSetting;

/** zarlio.ir public pages: home, terms, privacy (first simple version, owner 2026-10-07). */
class SiteController extends Controller
{
    public function home(CommercialConfig $config)
    {
        if (auth()->check()) {
            return redirect()->route('home');
        }
        $plans = collect($config->planCodes())->mapWithKeys(fn ($c) => [$c => $config->plan($c)]);

        return view('site.home', ['plans' => $plans, 'vat' => $config->vatRatePercent(), 'support' => PlatformSetting::supportPhone()]);
    }

    public function terms()
    {
        return view('site.terms', ['support' => PlatformSetting::supportPhone()]);
    }

    public function privacy()
    {
        return view('site.privacy', ['support' => PlatformSetting::supportPhone()]);
    }
}
