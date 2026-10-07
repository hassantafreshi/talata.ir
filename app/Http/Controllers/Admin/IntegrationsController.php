<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminActions;
use App\Domain\Audit\Audit;
use App\Domain\Billing\PaymentGateways;
use App\Domain\DomainError;
use App\Domain\Market\QuoteProvider;
use App\Domain\Market\QuoteService;
use App\Models\PlatformSetting;
use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'supportPhone' => PlatformSetting::supportPhone(), 'canSettings' => $this->staff()->allows('settings.manage'),
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

    /** Support phone shown to merchants (e.g. when a shop name needs staff approval). */
    public function saveSupport(Request $request, AdminActions $actions): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:20'], 'idempotency_key' => ['required', 'string']]);
        $reason = $this->reason($request);
        $raw = trim((string) ($data['phone'] ?? ''));
        $phone = $raw === '' ? null : (Mobile::normalize($raw) ?? (preg_match('/^0\d{10}$/', $digits = Digits::toLatin(preg_replace('/[\s\-]/', '', $raw))) ? $digits : false));
        if ($phone === false) {
            throw new DomainError('VALIDATION', 'شماره را با پیش‌شماره وارد کنید (موبایل یا تلفن ثابت ۱۱ رقمی).', 422, ['errors' => ['phone' => ['شماره درست نیست.']]]);
        }
        $result = $actions->once($this->staff(), 'platform.support_phone', $data['idempotency_key'], null, function () use ($phone, $reason) {
            $old = PlatformSetting::supportPhone();
            PlatformSetting::put('support.phone', $phone, $this->staff()->id);
            Audit::record('platform.support_phone_changed', null, ['from' => $old, 'to' => $phone, 'reason' => $reason], null, 'staff');

            return ['phone' => $phone];
        });

        return response()->json($result + ['message_fa' => 'شماره پشتیبانی ذخیره شد.']);
    }
}
