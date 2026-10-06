<?php

namespace Tests\Feature;

use App\Domain\Sms\SmsGateway;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileChangeTest extends TestCase
{
    use RefreshDatabase;

    private function lastCode(): string
    {
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);

        return $m[1];
    }

    public function test_two_otp_steps_change_the_login_number_and_drop_other_sessions(): void
    {
        $user = $this->merchant(mobile: '09120000001');
        $this->actingAs($user);
        // A session on another device for this account must not survive the change.
        DB::table('sessions')->insert([
            'id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '1.1.1.1',
            'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);

        $this->api('POST', '/api/security/mobile/start')->assertOk()->assertJsonPath('stage', 'old');
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk()->assertJsonPath('stage', 'await_new');
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000002'])->assertOk()->assertJsonPath('stage', 'new');
        $this->api('POST', '/api/security/mobile/confirm', ['code' => $this->lastCode()])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('09120000002', $user->fresh()->mobile);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.mobile_changed', 'actor_user_id' => $user->id]);
    }

    public function test_new_number_cannot_be_the_current_one_or_an_existing_account(): void
    {
        $taken = $this->merchant(mobile: '09120000011');
        $user = $this->merchant(mobile: '09120000010');
        $this->actingAs($user);

        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk();
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000010'])->assertStatus(422)->assertJsonPath('code', 'MOBILE_SAME');
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000011'])->assertStatus(422)->assertJsonPath('code', 'MOBILE_TAKEN');
        $this->assertSame('09120000010', $user->fresh()->mobile);
        $this->assertSame('09120000011', $taken->fresh()->mobile);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $user = $this->merchant(mobile: '09120000020');
        $this->actingAs($user);

        // No current-number verification yet.
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000021'])->assertStatus(409)->assertJsonPath('code', 'MCH_FLOW');
        $this->api('POST', '/api/security/mobile/confirm', ['code' => '123456'])->assertStatus(409)->assertJsonPath('code', 'MCH_FLOW');

        // A wrong current-number code leaves the flow unverified.
        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => '000000'])->assertStatus(422);
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000021'])->assertStatus(409)->assertJsonPath('code', 'MCH_FLOW');
    }

    public function test_other_devices_cannot_come_back_through_their_remember_me_cookie(): void
    {
        $user = $this->merchant(mobile: '09120000030');
        $user->forceFill(['remember_token' => 'old-device-token'])->save();
        $this->actingAs($user);

        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk();
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000031'])->assertOk();
        $this->api('POST', '/api/security/mobile/confirm', ['code' => $this->lastCode()])->assertOk();

        $fresh = $user->fresh();
        $this->assertNotSame('old-device-token', $fresh->remember_token);
        $this->assertNotEmpty($fresh->remember_token);
    }

    public function test_change_codes_never_read_like_a_login_code(): void
    {
        $user = $this->merchant(mobile: '09120000040');
        $this->actingAs($user);
        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $sent = app(SmsGateway::class)->sent;
        $body = end($sent)['body'];
        $this->assertStringContainsString('تغییر شماره ورود', $body);
        $this->assertStringNotContainsString('کد ورود زرلیو', $body);
    }

    public function test_one_verification_cannot_send_codes_to_many_numbers(): void
    {
        $user = $this->merchant(mobile: '09120000050');
        $this->actingAs($user);
        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk();
        foreach (['09120000051', '09120000052', '09120000053'] as $i => $n) {
            $this->travel(2)->minutes(); // past the per-number resend cooldown is irrelevant: each is a new number
            $this->api('POST', '/api/security/mobile/request-new', ['mobile' => $n])->assertOk();
        }
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000054'])->assertStatus(429)->assertJsonPath('code', 'MCH_TOO_MANY_TARGETS');
        // The verification is spent: starting over is required.
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000051'])->assertStatus(409)->assertJsonPath('code', 'MCH_FLOW');
    }

    public function test_current_number_verification_expires(): void
    {
        $user = $this->merchant(mobile: '09120000060');
        $this->actingAs($user);
        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk();
        $this->travel(11)->minutes();
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000061'])->assertStatus(409)->assertJsonPath('code', 'MCH_FLOW');
    }

    public function test_number_taken_between_request_and_confirm_is_refused_cleanly(): void
    {
        $user = $this->merchant(mobile: '09120000070');
        $this->actingAs($user);
        $this->api('POST', '/api/security/mobile/start')->assertOk();
        $this->api('POST', '/api/security/mobile/verify-current', ['code' => $this->lastCode()])->assertOk();
        $this->api('POST', '/api/security/mobile/request-new', ['mobile' => '09120000071'])->assertOk();
        $code = $this->lastCode();
        User::create(['mobile' => '09120000071']); // someone signs up with that number meanwhile
        $this->api('POST', '/api/security/mobile/confirm', ['code' => $code])->assertStatus(409)->assertJsonPath('code', 'MOBILE_TAKEN');
        $this->assertSame('09120000070', $user->fresh()->mobile);
    }

    public function test_the_endpoints_require_a_logged_in_user(): void
    {
        $this->api('POST', '/api/security/mobile/start')->assertStatus(401);
    }
}
