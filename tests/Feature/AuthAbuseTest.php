<?php

namespace Tests\Feature;

use App\Domain\Identity\ProofOfWork;
use App\Domain\Identity\TrustedDevice;
use App\Domain\Sms\SmsGateway;
use App\Models\SmsMessage;
use App\Models\User;
use App\Support\Digits;
use Tests\TestCase;

class AuthAbuseTest extends TestCase
{
    private function solvePow(): array
    {
        $c = $this->getJson('/api/auth/pow')->assertOk()->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }

        return ['pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce];
    }

    private function requestCode(string $mobile, array $extra = [], bool $wait = true)
    {
        $pow = $this->solvePow();
        if ($wait) {
            $this->travel(3)->seconds();
        }

        return $this->postJson('/api/auth/otp/request', ['mobile' => $mobile] + $pow + $extra);
    }

    private function lastCode(): string
    {
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);

        return $m[1];
    }

    public function test_login_with_otp_creates_tenant_and_session(): void
    {
        $this->get('/login')->assertOk();
        $this->requestCode('۰۹۱۲ ۳۴۵ ۶۷۸۹')->assertOk()->assertJsonPath('next', route('login.code'));
        $this->get('/login/code')->assertOk();
        $this->postJson('/api/auth/otp/verify', ['code' => '000000'])->assertStatus(422);
        $res = $this->postJson('/api/auth/otp/verify', ['code' => $this->lastCode()])->assertOk();
        $this->assertStringContainsString('/settings/business', $res->json('next'));
        $this->assertAuthenticated();
        $this->assertSame(1, User::query()->where('mobile', '09123456789')->count());
        // OTP SMS is operational: never charged to tenant credit or free allowance.
        $this->assertSame('OPERATIONAL', SmsMessage::query()->where('purpose', 'OTP')->value('charge_source'));
    }

    public function test_otp_requires_fresh_single_use_proof_of_work(): void
    {
        $this->postJson('/api/auth/otp/request', ['mobile' => '09123456789', 'pow_challenge' => str_repeat('a', 32), 'pow_nonce' => '1'])->assertStatus(422)->assertJsonPath('code', 'POW_INVALID');
        // Solved too fast (bot) is rejected.
        $this->requestCode('09123456789', [], false)->assertStatus(422)->assertJsonPath('code', 'POW_INVALID');
        // Replay of a used challenge is rejected.
        $pow = $this->solvePow();
        $this->travel(3)->seconds();
        $this->postJson('/api/auth/otp/request', ['mobile' => '09123456789'] + $pow)->assertOk();
        $this->travel(100)->seconds();
        $this->postJson('/api/auth/otp/request', ['mobile' => '09123456788'] + $pow)->assertStatus(422)->assertJsonPath('code', 'POW_INVALID');
    }

    public function test_cooldown_and_per_mobile_limits_stop_sms_bombing(): void
    {
        $this->requestCode('09123456789')->assertOk();
        $this->requestCode('09123456789')->assertStatus(429)->assertJsonPath('code', 'OTP_COOLDOWN');
        for ($i = 0; $i < 3; $i++) {
            $this->travel(181)->seconds();
            $this->requestCode('09123456789')->assertOk();
        }
        $this->travel(181)->seconds();
        $this->requestCode('09123456789')->assertStatus(429)->assertJsonPath('code', 'OTP_RATE_LIMITED');
        $this->assertSame(4, count(app(SmsGateway::class)->sent));
    }

    public function test_per_ip_limit_across_many_numbers(): void
    {
        $ok = 0;
        for ($i = 0; $i < 14; $i++) {
            $res = $this->requestCode('0912'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT));
            $ok += $res->status() === 200 ? 1 : 0;
        }
        $this->assertSame(config('talata.otp.per_ip_hour'), $ok);
    }

    public function test_honeypot_and_foreign_numbers_send_nothing(): void
    {
        $this->requestCode('09123456789', ['website' => 'http://spam'])->assertOk();
        $this->requestCode('+447700900123')->assertStatus(422)->assertJsonPath('code', 'MOBILE_INVALID');
        $this->requestCode('09803456789')->assertStatus(422);
        $this->assertSame([], app(SmsGateway::class)->sent);
    }

    public function test_wrong_codes_lock_the_mobile(): void
    {
        $this->requestCode('09123456789')->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', ['code' => '111111']);
        }
        $this->postJson('/api/auth/otp/verify', ['code' => $this->lastCode()])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_strangers_cannot_lock_a_known_device_out_of_its_own_number(): void
    {
        // The owner signed in on this phone before: the device is remembered for this number only.
        $this->requestCode('09123456789')->assertOk();
        $login = $this->postJson('/api/auth/otp/verify', ['code' => $this->lastCode()])->assertOk();
        $device = $login->getCookie(TrustedDevice::COOKIE)->getValue();
        $this->assertSame(TrustedDevice::value('09123456789'), $device);
        $this->post('/logout');
        $this->flushSession();
        $this->travel(4)->minutes();

        // A stranger (the number is printed on every invoice) uses up the anonymous allowance and locks it.
        for ($i = 0; $i < 4; $i++) {
            $this->travel(181)->seconds();
            $this->requestCode('09123456789');
        }
        $this->travel(181)->seconds();
        $this->requestCode('09123456789')->assertStatus(429)->assertJsonPath('code', 'OTP_RATE_LIMITED');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', ['code' => '111111']);
        }
        $this->flushSession();

        // The owner's phone still gets a code and signs in; a cookie for another number does not help.
        $this->withCredentials()->withCookie(TrustedDevice::COOKIE, $device); // JSON requests send cookies only with credentials
        $this->requestCode('09123456789')->assertOk();
        $this->postJson('/api/auth/otp/verify', ['code' => $this->lastCode()])->assertOk();
        $this->assertAuthenticated();
        $this->assertFalse(TrustedDevice::value('09120000000') === $device);
    }

    public function test_global_daily_otp_budget(): void
    {
        config(['talata.otp.global_daily_budget' => 2, 'talata.otp.per_ip_hour' => 100]);
        $this->requestCode('09123450001')->assertOk();
        $this->requestCode('09123450002')->assertOk();
        $this->requestCode('09123450003')->assertStatus(503)->assertJsonPath('code', 'OTP_BUDGET');
    }
}
