<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Market\QuoteService;
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

    public function test_owner_picks_screen_level_access_when_adding_a_member(): void
    {
        $owner = $this->merchant('professional');
        $this->actingAs($owner)->get('/settings/users')->assertOk()->assertSee('نقش آماده')->assertSee('فروشنده')->assertSee('ماشین‌حساب طلایی');
        $this->api('POST', '/api/users/invite', ['mobile' => '09371230001', 'permissions' => []])->assertStatus(422)->assertJsonPath('code', 'PERMISSIONS_EMPTY');

        // «فقط قیمت»: مظنه + ماشین‌حساب.
        $prices = $this->inviteAndAccept($owner, '09371230002', ['mazneh.view', 'calculator.use']);
        $this->actingAs($prices)->get('/home')->assertRedirect(route('mazneh'));
        $this->get('/mazneh')->assertOk();
        $this->get('/calculator')->assertOk()->assertDontSee('href="'.route('invoices.index').'"', false);
        foreach (['/invoices/new', '/invoices', '/customers', '/dashboard'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->api('GET', '/api/invoices')->assertForbidden();
        $this->api('GET', '/api/customers?q=09')->assertForbidden();
    }

    public function test_seller_sees_only_own_invoices_and_void_implies_viewing(): void
    {
        $owner = $this->merchant('professional');
        $seller = $this->inviteAndAccept($owner, '09371230003', ['invoice.issue', 'calculator.use']);
        $this->actingAs($seller)->get('/home')->assertRedirect(route('invoices.new'));
        $this->get('/invoices/new')->assertOk();
        $this->get('/mazneh')->assertForbidden();
        $this->get('/invoices')->assertForbidden();

        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $mk = function ($user) use ($rate) {
            $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
            $id = $res->json('draft_id');
            $v = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => [['item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750']]])->json('version');
            $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $v, 'idempotency_key' => 'k-'.bin2hex(random_bytes(8))])->assertCreated();

            return $id;
        };
        $mine = $mk($seller);
        $ownerInvoice = $mk($owner);
        $this->actingAs($seller)->get("/invoices/{$mine}/print")->assertOk();
        $this->get("/invoices/{$mine}/issued")->assertOk();
        $this->get("/invoices/{$ownerInvoice}")->assertForbidden();
        $this->api('GET', "/api/invoices/{$ownerInvoice}/status")->assertForbidden();
        $this->api('GET', "/api/invoices/{$mine}/status")->assertOk();

        // Another member's draft is not the seller's to open, change, delete or issue.
        $draft = $this->actingAs($owner)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $d = $draft->json('draft_id');
        $this->actingAs($seller)->get("/invoices/{$d}/items")->assertForbidden();
        $this->api('PUT', "/api/invoices/drafts/{$d}", ['version' => $draft->json('version'), 'rows' => []])->assertForbidden();
        $this->api('DELETE', "/api/invoices/drafts/{$d}")->assertForbidden();
        $this->api('POST', "/api/invoices/drafts/{$d}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $draft->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8))])->assertForbidden();
        // Quotes: the seller has the new-invoice screen (latest rate) but not the مظنه board.
        $this->api('GET', '/api/quotes/latest')->assertOk();
        $this->api('GET', '/api/quotes/board')->assertForbidden();

        // Giving «ابطال» also gives «فاکتورها» (dependency kept server-side).
        $m = Membership::query()->where('user_id', $seller->id)->where('tenant_id', $this->tenantOf($owner)->id)->first();
        $this->actingAs($owner)->api('PUT', "/api/users/{$m->id}", ['permissions' => ['invoice.void']])->assertOk();
        $this->assertSame(['invoices.view', 'invoice.void'], $m->fresh()->permissions);
        $this->actingAs($seller)->get("/invoices/{$ownerInvoice}")->assertOk();
    }
}
