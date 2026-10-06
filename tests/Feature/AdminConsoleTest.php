<?php

namespace Tests\Feature;

use App\Domain\Identity\ProofOfWork;
use App\Domain\Sms\SmsGateway;
use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Models\StaffUser;
use App\Support\TechLog;
use Illuminate\Support\Facades\DB;
use Tests\Support\SoftAuthenticator;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    private function staff(string $role = 'admin', string $mobile = '09120000001'): StaffUser
    {
        return StaffUser::query()->create(['mobile' => $mobile, 'name' => 'مدیر آزمون', 'role' => $role, 'active' => true]);
    }

    private function powPayload(): array
    {
        $c = $this->getJson('/admin/api/pow')->assertOk()->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }
        $this->travel(3)->seconds();

        return ['pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce];
    }

    private function signIn(StaffUser $staff): void
    {
        $r = $this->postJson('/admin/api/otp/request', ['mobile' => $staff->mobile] + $this->powPayload());
        $this->assertSame(200, $r->status(), $r->getContent());
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', end($sent)['body'], $m);
        $this->postJson('/admin/api/otp/verify', ['code' => $m[1]])->assertOk()->assertJsonPath('next', route('admin.dashboard'));
    }

    public function test_merchants_and_guests_cannot_reach_the_console(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant)->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/activity')->assertRedirect(route('admin.login'));
        $this->getJson('/admin/tech')->assertStatus(401);
        // There is no merchant-side route that exposes activity or technical logs.
        foreach (['/api/activity', '/api/logs', '/settings/activity'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_non_staff_mobile_gets_no_sms_and_same_response(): void
    {
        $this->postJson('/admin/api/otp/request', ['mobile' => '09129999999'] + $this->powPayload())->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([], app(SmsGateway::class)->sent);
        $this->assertTrue(DB::connection('pgsql_log')->table('system_logs')->where('service', 'admin')->exists());
    }

    public function test_staff_sign_in_and_view_logs_per_user_and_service(): void
    {
        // Some merchant activity to look at.
        $merchant = $this->merchant();
        $this->actingAs($merchant)->api('POST', '/api/customers', ['name' => 'مشتری', 'mobile' => '09351110000'])->assertCreated();
        TechLog::warning('kavenegar', 'kavenegar send', ['http' => 500]);
        auth()->logout();

        $staff = $this->staff();
        $this->signIn($staff);
        $this->get('/admin')->assertOk()->assertSee('داشبورد');
        $this->get('/admin/activity')->assertOk()->assertSee('ثبت مشتری')->assertSee('ورود مدیر');
        $this->get('/admin/activity?service=customers')->assertOk()->assertSee('ثبت مشتری')->assertDontSee('ورود مدیر');
        $this->get('/admin/activity?mobile='.$merchant->mobile)->assertOk()->assertSee('ثبت مشتری');
        $this->get('/admin/users/'.$merchant->id)->assertOk()->assertSee('فعالیت به تفکیک سرویس');
        $tenantId = $this->tenantOf($merchant)->id;
        $this->get('/admin/tenants')->assertOk()->assertSee('طلافروشی آزمون');
        $this->get('/admin/tenants/'.$tenantId)->assertOk();
        $this->get('/admin/tech?service=kavenegar')->assertOk()->assertSee('kavenegar send');
        $this->get('/admin/tech?level=error')->assertOk()->assertDontSee('kavenegar send');

        $created = AuditEvent::query()->where('event', 'customer.created')->first();
        $this->assertSame('customers', $created->service);
        $this->assertSame($merchant->id, $created->actor_user_id);
        $this->assertNotNull($created->request_id);
        $this->assertSame(1, AuditEvent::query()->where('event', 'admin.viewed_user')->where('staff_id', $staff->id)->count());
    }

    public function test_support_role_cannot_see_technical_logs(): void
    {
        $this->signIn($this->staff('support'));
        $this->get('/admin/activity')->assertOk();
        $this->get('/admin/tech')->assertForbidden();
    }

    public function test_idle_timeout_and_deactivation_end_the_session(): void
    {
        $staff = $this->staff();
        $this->signIn($staff);
        $this->get('/admin')->assertOk();
        $this->travel(31)->minutes();
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->signIn($staff);
        $staff->update(['active' => false]);
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_ip_allowlist_hides_the_console(): void
    {
        config(['talata.admin.allowed_ips' => '10.1.2.3']);
        $this->get('/admin/login')->assertNotFound();
    }

    public function test_staff_passkey_sign_in(): void
    {
        config(['app.url' => 'http://localhost:8000', 'talata.webauthn.rp_id' => null, 'talata.webauthn.origins' => null]);
        $staff = $this->staff();
        $this->signIn($staff);
        $device = new SoftAuthenticator('http://localhost:8000', 'localhost');
        $opts = $this->postJson('/admin/api/account/passkeys/options')->assertOk()->json();
        $this->postJson('/admin/api/account/passkeys', ['credential' => $device->create($opts)])->assertCreated();
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $opts = $this->postJson('/admin/api/passkey/options')->json();
        $this->postJson('/admin/api/passkey/verify', ['credential' => $device->get($opts)])->assertOk();
        $this->get('/admin')->assertOk();
        // A merchant passkey can never sign in to the console (separate owner type).
        $this->assertSame(0, Passkey::query()->where('owner_type', 'user')->count());
    }
}
