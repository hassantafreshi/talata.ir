<?php

namespace Tests\Feature;

use App\Domain\DomainError;
use App\Domain\Identity\LoginService;
use App\Domain\Identity\OtpService;
use App\Domain\Identity\ProofOfWork;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsGateway;
use App\Models\Membership;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpLifecycleTest extends TestCase
{
    private function lastCode(): string
    {
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);

        return $m[1];
    }

    private function expectCode(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (DomainError $e) {
            $this->assertSame($code, $e->codeName);
        }
    }

    public function test_code_expires_and_is_single_use_and_a_resend_retires_the_old_code(): void
    {
        $otp = app(OtpService::class);
        $first = $otp->request('09121110000', '10.0.0.1');
        $firstCode = $this->lastCode();

        // Expired after its TTL.
        $this->travel(config('talata.otp.ttl_seconds') + 1)->seconds();
        $this->expectCode(fn () => $otp->verify($first['challenge_id'], $firstCode, '10.0.0.1'), 'OTP_EXPIRED');

        // A resend retires the previous code even before it expires.
        $this->travel(config('talata.otp.resend_cooldown_seconds') + 1)->seconds();
        $second = $otp->request('09121110000', '10.0.0.1');
        $secondCode = $this->lastCode();
        $this->travel(config('talata.otp.resend_cooldown_seconds') + 1)->seconds();
        $third = $otp->request('09121110000', '10.0.0.1');
        $thirdCode = $this->lastCode();
        $this->expectCode(fn () => $otp->verify($second['challenge_id'], $secondCode, '10.0.0.1'), 'OTP_EXPIRED');

        // Single use: the right code works once, then never again.
        $this->assertSame('09121110000', $otp->verify($third['challenge_id'], $thirdCode, '10.0.0.1'));
        $this->expectCode(fn () => $otp->verify($third['challenge_id'], $thirdCode, '10.0.0.1'), 'OTP_EXPIRED');
    }

    public function test_login_rotates_the_session_id_and_logout_ends_it(): void
    {
        $this->get('/login')->assertOk();
        $before = session()->getId();
        $c = $this->getJson('/api/auth/pow')->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }
        $this->travel(3)->seconds();
        $this->postJson('/api/auth/otp/request', ['mobile' => '09121110001', 'pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce])->assertOk();
        $this->postJson('/api/auth/otp/verify', ['code' => $this->lastCode()])->assertOk();
        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId(), 'session fixation: id must change at login');

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_first_login_is_idempotent(): void
    {
        $login = app(LoginService::class);
        $a = $login->completeLogin('09121110002');
        $b = $login->completeLogin('09121110002');

        $this->assertTrue($a['is_new_tenant']);
        $this->assertFalse($b['is_new_tenant']);
        $this->assertSame(1, User::query()->where('mobile', '09121110002')->count());
        $this->assertSame(1, Membership::query()->where('user_id', $a['user']->id)->where('role', 'owner')->count());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_a_shop_with_no_sms_allowance_left_can_still_sign_in(): void
    {
        $owner = $this->merchant(mobile: '09121110003');
        $tenant = $this->tenantOf($owner);
        // Free SMS for the year used up and no credit: invoice SMS are blocked for this shop…
        foreach (range(1, 5) as $i) {
            DB::table('sms_messages')->insert([
                'public_id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenant->id, 'purpose' => 'INVOICE',
                'recipient' => '09351234567', 'body' => 'x', 'payload' => '{}', 'segments' => 1, 'cost_irr' => '0',
                'charge_source' => 'FREE_YEARLY', 'status' => 'SENT', 'idempotency_key' => 'free-'.$i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame(0, app(Entitlements::class)->summary($tenant)['free_sms_remaining']);

        // …but the owner's login code is operational and never charged to the shop.
        $otp = app(OtpService::class);
        $c = $otp->request('09121110003', '10.0.0.2');
        $this->assertSame('09121110003', $otp->verify($c['challenge_id'], $this->lastCode(), '10.0.0.2'));
        $this->assertSame('OPERATIONAL', SmsMessage::query()->where('purpose', 'OTP')->latest('id')->value('charge_source'));
        $this->assertNull(SmsMessage::query()->where('purpose', 'OTP')->latest('id')->value('tenant_id'));
    }

    public function test_sign_out_other_devices_ends_their_sessions_and_remember_me(): void
    {
        $user = $this->merchant(mobile: '09121110004');
        $user->forceFill(['remember_token' => 'lost-phone-token'])->save();
        DB::table('sessions')->insert(['id' => 'lost-phone', 'user_id' => $user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($user)->api('POST', '/api/security/sign-out-others')->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'lost-phone']);
        $this->assertNotSame('lost-phone-token', $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.signed_out_other_devices', 'actor_user_id' => $user->id]);
    }

    private function requestViaHttp(string $mobile): void
    {
        $c = $this->getJson('/api/auth/pow')->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }
        $this->travel(3)->seconds();
        $this->postJson('/api/auth/otp/request', ['mobile' => $mobile, 'pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce])->assertOk();
    }

    public function test_code_page_learns_when_the_login_sms_failed(): void
    {
        // No code requested in this session: nothing to reveal.
        $this->getJson('/api/auth/otp/status')->assertOk()->assertJsonPath('state', 'sent');

        app(SmsGateway::class)->nextStatus = 'FAILED';
        $this->requestViaHttp('09121110020');
        $this->getJson('/api/auth/otp/status')->assertOk()->assertJsonPath('state', 'failed')->assertHeader('Cache-Control', 'no-store, private');

        app(SmsGateway::class)->nextStatus = 'SENT';
        $this->travel(config('talata.otp.resend_cooldown_seconds') + 1)->seconds();
        $this->requestViaHttp('09121110020');
        $this->getJson('/api/auth/otp/status')->assertOk()->assertJsonPath('state', 'sent');
    }
}
