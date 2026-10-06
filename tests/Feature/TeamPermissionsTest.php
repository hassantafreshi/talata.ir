<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Models\Membership;
use App\Models\PricingVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TeamPermissionsTest extends TestCase
{
    private function inviteAndAccept(User $owner, string $mobile, ?array $permissions = null): User
    {
        $payload = ['mobile' => $mobile] + ($permissions !== null ? ['permissions' => $permissions] : []);
        $this->actingAs($owner)->api('POST', '/api/users/invite', $payload)->assertOk();
        $member = app(LoginService::class)->completeLogin($mobile)['user'];
        $invite = Membership::query()->where('invited_mobile', $mobile)->where('status', 'invited')->firstOrFail();
        $this->actingAs($member)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();

        return $member;
    }

    public function test_free_plan_members_always_have_full_access_and_cannot_be_restricted(): void
    {
        $owner = $this->merchant();
        // Even if a restricted list is sent, Free stores full access.
        $member = $this->inviteAndAccept($owner, '09371110001', ['invoice.issue']);
        $m = Membership::query()->where('user_id', $member->id)->where('tenant_id', $this->tenantOf($owner)->id)->first();
        $this->assertEqualsCanonicalizing(Membership::allPermissions(), $m->permissions);
        $this->get('/settings/business')->assertOk();
        $this->get('/settings/sms')->assertOk()->assertSee('پرداخت و شارژ');
        $this->get('/settings/users')->assertForbidden(); // managing users stays owner-only
        $this->actingAs($owner)->get('/settings/users')->assertOk()->assertSee('دسترسی کامل')->assertDontSee('data-perm', false);
        $this->api('PUT', "/api/users/{$m->id}", ['permissions' => ['invoice.issue']])->assertStatus(403)->assertJsonPath('code', 'CAPABILITY_TEAM_PERMISSIONS_EDIT');
    }

    public function test_basic_plan_can_restrict_and_downgrade_restores_full_access(): void
    {
        $owner = $this->merchant('basic');
        $member = $this->inviteAndAccept($owner, '09371110002');
        $tenant = $this->tenantOf($owner);
        $m = Membership::query()->where('user_id', $member->id)->where('tenant_id', $tenant->id)->first();
        $this->assertEqualsCanonicalizing(Membership::allPermissions(), $m->permissions, 'default is full access');
        $this->actingAs($owner)->get('/settings/users')->assertOk()->assertSee('data-perm', false);
        $this->api('PUT', "/api/users/{$m->id}", ['permissions' => ['invoice.issue']])->assertOk();
        $this->actingAs($member)->get('/settings/business')->assertForbidden();
        $this->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'idempotency_key' => 'abcdefgh99'])->assertForbidden();
        $this->get('/invoices/new')->assertOk();

        // Plan ends → Free: stored restrictions are kept but no longer applied.
        app(TenantContext::class)->runAs($tenant, fn () => Subscription::query()->update(['status' => 'superseded']));
        $this->actingAs($member)->get('/settings/business')->assertOk();
    }

    public function test_member_limit_is_a_configurable_quota(): void
    {
        $owner = $this->merchant();
        $tenant = $this->tenantOf($owner);
        $this->actingAs($owner);
        for ($i = 1; $i <= 9; $i++) {
            $this->api('POST', '/api/users/invite', ['mobile' => '0937222000'.$i])->assertOk();
        }
        $this->api('POST', '/api/users/invite', ['mobile' => '09372229999'])->assertStatus(422)->assertJsonPath('code', 'MEMBER_LIMIT');
        $this->assertSame(10, Membership::query()->where('tenant_id', $tenant->id)->whereIn('status', ['active', 'invited'])->count());
    }

    public function test_restrictions_fail_closed_when_pricing_lacks_the_capability(): void
    {
        $owner = $this->merchant('basic');
        $member = $this->inviteAndAccept($owner, '09371110003');
        $tenant = $this->tenantOf($owner);
        $m = Membership::query()->where('user_id', $member->id)->where('tenant_id', $tenant->id)->first();
        $this->actingAs($owner)->api('PUT', "/api/users/{$m->id}", ['permissions' => ['invoice.issue']])->assertOk();
        // Simulate an older pricing version without the new keys.
        $row = PricingVersion::query()->first();
        $payload = $row->payload;
        foreach ($payload['plans'] as $code => $p) {
            unset($payload['plans'][$code]['capabilities']['team.permissions_edit'], $payload['plans'][$code]['quotas']['team_members']);
        }
        $row->update(['payload' => $payload]);
        Cache::forget('talata.pricing.active');
        $this->app->forgetScopedInstances();
        $this->actingAs($member)->get('/settings/business')->assertForbidden();
    }
}
