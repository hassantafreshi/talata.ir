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

    public function test_the_endpoints_require_a_logged_in_user(): void
    {
        $this->api('POST', '/api/security/mobile/start')->assertStatus(401);
    }
}
