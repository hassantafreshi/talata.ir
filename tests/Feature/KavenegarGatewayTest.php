<?php

namespace Tests\Feature;

use App\Domain\Sms\Gateways\KavenegarSmsGateway;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class KavenegarGatewayTest extends TestCase
{
    private const KEY = 'TEST-SECRET-KEY-6A2B';

    private function gateway(?string $template = 'talata-otp'): KavenegarSmsGateway
    {
        config(['services.kavenegar.api_key' => self::KEY]);

        return new KavenegarSmsGateway(self::KEY, '10004346', $template);
    }

    private function ok(int $messageId, int $status = 1): array
    {
        return ['return' => ['status' => 200, 'message' => 'تایید شد'], 'entries' => [['messageid' => $messageId, 'status' => $status, 'receptor' => '09121234567', 'cost' => 1200]]];
    }

    public function test_send_posts_form_and_maps_success(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response($this->ok(8792343))]);
        $r = $this->gateway()->send('09121234567', 'فاکتور شما');
        $this->assertSame(['status' => 'SENT', 'provider_id' => '8792343', 'error' => null], $r);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.kavenegar.com/v1/'.self::KEY.'/sms/send.json'
            && $req['receptor'] === '09121234567' && $req['sender'] === '10004346' && $req['message'] === 'فاکتور شما');
    }

    public function test_otp_uses_verify_lookup_template_with_token(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response($this->ok(55))]);
        $r = $this->gateway()->sendOtp('09121234567', '482913', 'body');
        $this->assertSame('SENT', $r['status']);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/verify/lookup.json') && $req['token'] === '482913' && $req['template'] === 'talata-otp');
        // Without a template, falls back to sms/send with the full body.
        $this->gateway(null)->sendOtp('09121234567', '482913', 'کد ورود: 482913');
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/sms/send.json') && $req['message'] === 'کد ورود: 482913');
    }

    public function test_mobile_change_code_never_uses_the_login_template(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response($this->ok(56))]);
        // Own template configured → lookup with that template.
        (new KavenegarSmsGateway(self::KEY, '10004346', 'talata-otp', 'https://api.kavenegar.com/v1', 10, 'talata-mobile-change'))
            ->sendOtp('09121234567', '111222', 'body', 'mobile_change');
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/verify/lookup.json') && $req['template'] === 'talata-mobile-change');
        // No own template → plain send with our wording, never the login template.
        $this->gateway('talata-otp')->sendOtp('09121234567', '333444', 'کد تغییر شماره ورود زرلیو: 333444', 'mobile_change');
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/sms/send.json') && str_contains($req['message'], 'تغییر شماره'));
        Http::assertNotSent(fn (Request $req) => ($req['template'] ?? null) === 'talata-otp' && ($req['token'] ?? null) === '333444');
    }

    public function test_account_info_checks_the_key_without_sending_anything(): void
    {
        Http::fakeSequence('api.kavenegar.com/*')
            ->push(['return' => ['status' => 200, 'message' => 'تایید شد'], 'entries' => ['remaincredit' => 1250000, 'expiredate' => 1893456000, 'type' => 'master']])
            ->push(['return' => ['status' => 401, 'message' => 'حساب کاربری غیرفعال شده است'], 'entries' => null], 401);
        $info = $this->gateway()->accountInfo();
        $this->assertTrue($info['ok']);
        $this->assertSame(1250000, $info['credit_irr']);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/account/info.json'));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), '/sms/') || str_contains($req->url(), '/verify/'));

        $bad = $this->gateway()->accountInfo();
        $this->assertFalse($bad['ok']);
        $this->assertStringNotContainsString(self::KEY, (string) $bad['error']);
    }

    public function test_definite_errors_fail_and_ambiguous_errors_are_unknown(): void
    {
        Http::fakeSequence('api.kavenegar.com/*')
            ->push(['return' => ['status' => 418, 'message' => 'اعتبار کافی نیست'], 'entries' => null], 418)
            ->push(['return' => ['status' => 411, 'message' => 'گیرنده نامعتبر'], 'entries' => null], 411)
            ->push(['return' => ['status' => 500, 'message' => 'خطای سرور'], 'entries' => null], 500)
            ->push('', 502)
            ->push($this->ok(9, 6));
        $g = $this->gateway();
        $this->assertSame('FAILED', $g->send('09121234567', 'x')['status']);
        $this->assertSame('FAILED', $g->send('09121234567', 'x')['status']);
        $this->assertSame('UNKNOWN', $g->send('09121234567', 'x')['status']);
        $this->assertSame('UNKNOWN', $g->send('09121234567', 'x')['status']);
        $this->assertSame('FAILED', $g->send('09121234567', 'x')['status'], 'entry status 6 = failed');
    }

    public function test_network_error_is_unknown_and_api_key_is_never_logged(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.kavenegar.com/v1/'.self::KEY.'/sms/send.json'));
        $r = $this->gateway()->send('09121234567', 'x');
        $this->assertSame('UNKNOWN', $r['status']);
        $this->assertStringNotContainsString(self::KEY, $r['error']);
        $logs = DB::connection('pgsql_log')->table('system_logs')->where('service', 'kavenegar')->get();
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertStringNotContainsString(self::KEY, $log->message.$log->context);
            $this->assertStringNotContainsString('09121234567', $log->context, 'mobile masked in technical log');
        }
    }

    public function test_status_mapping(): void
    {
        Http::fakeSequence('api.kavenegar.com/*')->push($this->ok(1, 10))->push($this->ok(1, 4))->push($this->ok(1, 11))->push($this->ok(1, 100));
        $g = $this->gateway();
        $this->assertSame(['DELIVERED', 'SENT', 'UNDELIVERED', 'UNKNOWN'], [$g->status('1'), $g->status('1'), $g->status('1'), $g->status('1')]);
    }

    public function test_otp_code_is_never_stored_in_plain_text(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response($this->ok(77))]);
        $this->app->instance(SmsGateway::class, $this->gateway());
        $msg = app(SmsService::class)->queueOtp('09121234567', '135790', strtolower((string) Str::ulid()));
        $raw = DB::table('sms_messages')->where('id', $msg->id)->first();
        $this->assertStringNotContainsString('135790', $raw->body);
        $this->assertStringNotContainsString('135790', (string) $raw->payload, 'payload is encrypted');
        // Queue is sync in tests: after sending, the encrypted payload is erased as well.
        $after = DB::table('sms_messages')->where('id', $msg->id)->first();
        $this->assertNull($after->payload);
        $this->assertSame('SENT', $after->status);
        $this->assertSame('kavenegar', $after->provider);
        Http::assertSent(fn (Request $req) => ($req['token'] ?? null) === '135790');
    }

    public function test_production_refuses_dev_sms_driver(): void
    {
        $this->app['env'] = 'production';
        config(['talata.drivers.sms' => 'log']);
        $this->app->forgetInstance(SmsGateway::class);
        $this->expectException(\RuntimeException::class);
        app(SmsGateway::class);
    }

    public function test_send_passes_local_id_and_reconcile_never_refunds_charged_messages(): void
    {
        $user = $this->merchant('basic');
        $tenant = $this->tenantOf($user);
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate(['tenant_id' => $tenant->id, 'amount_irr' => '100000', 'remaining_irr' => '100000', 'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'plan_at_purchase' => 'basic']);
        $credit = app(SmsCredit::class);
        $make = function (string $status, ?string $providerId) use ($tenant, $credit) {
            $m = SmsMessage::query()->create(['tenant_id' => $tenant->id, 'purpose' => 'INVOICE', 'recipient' => '09351234567', 'body' => 'x', 'segments' => 1,
                'cost_irr' => '5000', 'charge_source' => 'CREDIT', 'status' => 'SENDING', 'idempotency_key' => 'k'.uniqid(), 'provider_message_id' => $providerId]);
            $this->assertTrue($credit->reserve($tenant->id, '5000', $m));
            $m->forceFill(['status' => $status])->save();
            DB::table('sms_messages')->where('id', $m->id)->update(['updated_at' => now()->subMinutes(40)]);

            return $m;
        };
        $undelivered = $make('UNKNOWN', '111');   // provider says 11: sent but undelivered → charged
        $neverSent = $make('UNKNOWN', null);      // lookup by localid: not found → refund
        $foundLocal = $make('UNKNOWN', null);     // lookup by localid: found and sent → charged
        $this->app->instance(SmsGateway::class, $this->gateway());
        Http::fake(function (Request $req) use ($foundLocal) {
            if (str_ends_with($req->url(), '/sms/status.json')) {
                return Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 111, 'status' => 11]]]);
            }
            if (str_ends_with($req->url(), '/sms/statuslocalmessageid.json')) {
                return $req['localid'] === $foundLocal->public_id
                    ? Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 222, 'localid' => $req['localid'], 'status' => 10]]])
                    : Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 0, 'status' => 100]]]);
            }

            return Http::response([], 404);
        });
        $this->artisan('talata:sms-reconcile')->assertSuccessful();

        $this->assertSame('SENT', $undelivered->fresh()->status);
        $this->assertStringContainsString('undelivered', $undelivered->fresh()->last_error);
        $this->assertSame('FAILED', $neverSent->fresh()->status);
        $this->assertSame('DELIVERED', $foundLocal->fresh()->status);
        $this->assertSame('222', $foundLocal->fresh()->provider_message_id);
        // 100000 − two charged messages (5000 each); the never-sent one was refunded.
        $this->assertSame('90000', $credit->balance($tenant->id));

        Http::fake(['api.kavenegar.com/*' => Http::response($this->ok(5))]);
        $this->gateway()->send('09121234567', 'x', 'localid-abc');
        Http::assertSent(fn (Request $req) => ($req['localid'] ?? null) === 'localid-abc');
    }
}
