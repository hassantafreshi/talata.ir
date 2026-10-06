<?php

namespace Tests\Feature;

use App\Domain\Billing\PaymentGateways;
use App\Domain\Sms\Gateways\KavenegarSmsGateway;
use App\Domain\Sms\SmsGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreflightTest extends TestCase
{
    private function providersConfigured(): void
    {
        config([
            'talata.drivers.payment' => 'zarinpal',
            'talata.payments.zarinpal.merchant_id' => '11111111-2222-3333-4444-555555555555',
            'talata.payments.zarinpal.sandbox' => false,
            'talata.drivers.sms' => 'kavenegar',
            'services.kavenegar.api_key' => 'TEST-KEY',
        ]);
        $this->app->forgetInstance(PaymentGateways::class);
        $this->app->instance(SmsGateway::class, new KavenegarSmsGateway('TEST-KEY', '10004346', 'talata-otp'));
    }

    public function test_live_mode_checks_providers_without_sending_sms_or_creating_payments(): void
    {
        $this->providersConfigured();
        Cache::forever('talata.sched.payments-reconcile', ['at' => now()->toIso8601String(), 'ok' => true, 'duration_ms' => 12]);
        Http::fake([
            'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200, 'message' => 'تایید شد'], 'entries' => ['remaincredit' => 990000, 'expiredate' => 1893456000]]),
            'payment.zarinpal.com/*' => Http::response('<html></html>', 200),
        ]);

        Artisan::call('talata:preflight', ['--live' => true, '--allow-warnings' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('Kavenegar (live)', $out);
        $this->assertStringContainsString('key valid', $out);
        $this->assertStringContainsString('ZarinPal (live)', $out);
        $this->assertStringContainsString('reachable', $out);
        $this->assertStringContainsString('payments-reconcile last ran', $out);
        // No side effects at the providers.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/sms/send') || str_contains($r->url(), '/verify/lookup') || str_contains($r->url(), 'payment/request'));
    }

    public function test_live_mode_reports_a_rejected_key_and_a_dead_scheduler(): void
    {
        $this->providersConfigured();
        Http::fake([
            'api.kavenegar.com/*' => Http::response(['return' => ['status' => 401, 'message' => 'حساب کاربری غیرفعال شده است'], 'entries' => null], 401),
            'payment.zarinpal.com/*' => Http::response('', 200),
        ]);

        $code = Artisan::call('talata:preflight', ['--live' => true, '--allow-warnings' => true]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('key rejected or unreachable', $out);
        $this->assertStringNotContainsString('TEST-KEY', $out);
        $this->assertStringContainsString('never ran', $out);
    }
}
