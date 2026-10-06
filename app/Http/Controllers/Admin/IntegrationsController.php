<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\PaymentGateways;
use App\Domain\Market\QuoteProvider;
use App\Domain\Market\QuoteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Integrations (docs/handoff/04_SCREENS_ADMIN.md A-09), read-only in v1: secrets live in the server
 * environment (never in the database or this page); the page shows whether each is set, never its value.
 */
class IntegrationsController extends AdminController
{
    public function index(PaymentGateways $gateways, QuoteProvider $provider, QuoteService $quotes)
    {
        $default = $gateways->default();
        $zp = config('talata.payments.zarinpal');
        $kv = config('services.kavenegar', []);
        $lastSmsError = DB::connection('pgsql_log')->table('system_logs')->where('service', 'sms')->whereIn('level', ['warning', 'error'])
            ->orderByDesc('id')->first(['created_at', 'message']);
        $assets = collect(QuoteService::ASSETS)->filter(fn ($a) => $quotes->feed($a))->count();

        return view('admin.integrations', [
            'payment' => [
                'driver' => $default->code(), 'is_mock' => $default->isMock(), 'registered' => $gateways->codes(),
                'zarinpal_merchant_set' => (bool) ($zp['merchant_id'] ?? null), 'zarinpal_sandbox' => (bool) ($zp['sandbox'] ?? false),
                'callback' => route('pay.callback', $default->code()), 'expiry' => config('talata.payments.order_expiry_minutes'),
                'reconcile_hours' => config('talata.payments.reconcile_max_hours'), 'send_mobile' => (bool) ($zp['send_mobile'] ?? false),
            ],
            'sms' => [
                'driver' => config('talata.drivers.sms'), 'sender_set' => (bool) ($kv['sender'] ?? null), 'key_set' => (bool) ($kv['api_key'] ?? null),
                'otp_template_set' => (bool) ($kv['otp_template'] ?? null), 'last_error' => $lastSmsError,
            ],
            'quotes' => [
                'driver' => config('talata.drivers.quotes'), 'name' => $provider->name(), 'is_demo' => $provider->isDemo(),
                'assets' => $assets, 'total' => count(QuoteService::ASSETS), 'last_error' => Cache::get('talata.quotes.last_error'),
            ],
            'publicUrl' => config('talata.public_url'),
            'env' => app()->environment(),
            'canTestSms' => $this->staff()->allows('sms.manage'),
        ]);
    }
}
