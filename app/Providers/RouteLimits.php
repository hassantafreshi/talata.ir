<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Named per-route limits. Keys combine tenant and user so one shop cannot exhaust another's budget. */
final class RouteLimits
{
    public static function register(): void
    {
        $key = fn (Request $r) => ($r->session()->get('tenant_id') ?? 'guest').'|'.($r->user()?->id ?? $r->ip());

        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(config('talata.public.verify_per_minute'))->by($r->ip()));
        RateLimiter::for('drafts', fn (Request $r) => Limit::perMinute(config('talata.invoices.draft_save_per_minute'))->by($key($r)));
        RateLimiter::for('issue', fn (Request $r) => Limit::perMinute(config('talata.invoices.issue_per_minute'))->by($key($r)));
        RateLimiter::for('customers', fn (Request $r) => Limit::perMinute(config('talata.customers.create_per_minute'))->by($key($r)));
        // Login-number change: its own per-user counters (each step can send an SMS), not shared with other routes.
        RateLimiter::for('mobile-change', fn (Request $r) => [
            Limit::perMinutes(10, 10)->by('mch|'.($r->user()?->id ?? $r->ip())),
            Limit::perDay(30)->by('mch-day|'.($r->user()?->id ?? $r->ip())),
        ]);
        RateLimiter::for('billing', fn (Request $r) => [
            Limit::perHour(config('talata.payments.orders_per_hour'))->by('billing|'.$key($r)),
        ]);
    }
}
