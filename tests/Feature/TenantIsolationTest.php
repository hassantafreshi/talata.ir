<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Market\QuoteService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Membership;
use App\Tenancy\TenantContext;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_no_cross_tenant_access_by_id(): void
    {
        $a = $this->merchant('professional');
        $b = $this->merchant('professional');
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $draft = $this->actingAs($a)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->json('draft_id');
        $customer = $this->api('POST', '/api/customers', ['name' => 'مشتری الف', 'mobile' => '09351112233'])->assertCreated()->json('id');

        $this->actingAs($b);
        foreach (["/invoices/{$draft}/items", "/invoices/{$draft}/review", "/invoices/{$draft}", "/invoices/{$draft}/print", "/customers/{$customer}", "/customers/{$customer}/agreements/new"] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->api('PUT', "/api/invoices/drafts/{$draft}", ['version' => 1, 'rows' => []])->assertNotFound();
        $this->api('DELETE', "/api/invoices/drafts/{$draft}")->assertNotFound();
        $this->api('POST', "/api/invoices/drafts/{$draft}/issue", ['mode' => 'ISSUE_ONLY', 'version' => 1, 'idempotency_key' => 'abcdefgh123'])->assertNotFound();
        $this->api('PUT', "/api/customers/{$customer}", ['name' => 'x'])->assertNotFound();
        $this->api('POST', "/api/customers/{$customer}/agreements", ['count' => 3, 'frequency' => 'monthly', 'first_due' => '1406/01/01'])->assertNotFound();
        $this->api('GET', '/api/customers?q=الف')->assertOk()->assertJsonCount(0, 'items');
        $this->api('GET', '/api/invoices')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(1, Invoice::withoutGlobalScope('tenant')->count());
    }

    public function test_scope_fails_closed_without_tenant_context(): void
    {
        $a = $this->merchant();
        $this->inTenant($a, fn () => Customer::create(['name' => 'x']));
        app(TenantContext::class)->clear();
        $this->assertSame(0, Customer::query()->count());
        $this->expectException(\Throwable::class);
        Customer::create(['name' => 'orphan']);
    }

    public function test_removed_member_loses_access_immediately_and_permissions_are_enforced(): void
    {
        $owner = $this->merchant();
        $this->actingAs($owner)->api('POST', '/api/users/invite', ['mobile' => '09361112233', 'permissions' => ['invoice.issue']])->assertOk();
        $staff = app(LoginService::class)->completeLogin('09361112233')['user'];
        // Not auto-accepted: the staff member got their own shop and a pending invite.
        $this->assertNotSame($this->tenantOf($owner)->id, $this->tenantOf($staff)->id);
        $invite = Membership::query()->where('invited_mobile', '09361112233')->where('status', 'invited')->firstOrFail();
        $this->actingAs($this->merchant())->api('POST', "/api/invites/{$invite->id}/accept")->assertNotFound();
        $this->actingAs($staff)->get('/settings')->assertOk()->assertSee('دعوت به فروشگاه');
        $this->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();
        $this->get('/settings/users')->assertForbidden();
        $this->get('/settings/business')->assertForbidden();
        $this->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'abcdefgh12'])->assertForbidden();
        $this->get('/invoices/new')->assertOk();
        $ownerTenant = $this->tenantOf($owner)->id;
        $m = Membership::query()->where('user_id', $staff->id)->where('tenant_id', $ownerTenant)->first();
        $this->actingAs($owner)->api('DELETE', "/api/users/{$m->id}")->assertOk();
        // Removed: falls back to their own shop; no active membership in the owner's shop remains.
        $this->actingAs($staff)->get('/invoices/new')->assertOk();
        $this->assertSame(0, Membership::query()->where('user_id', $staff->id)->where('tenant_id', $ownerTenant)->where('status', 'active')->count());
    }
}
