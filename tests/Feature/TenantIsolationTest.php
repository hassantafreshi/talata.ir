<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Market\QuoteService;
use App\Domain\Plans\Entitlements;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $owner = $this->merchant('basic'); // restricting permissions is a Basic/Pro capability
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

    public function test_two_shops_never_share_files_job_effects_or_cached_entitlements(): void
    {
        Storage::fake('local');
        $a = $this->merchant('professional');
        $b = $this->merchant('free');
        $ta = $this->tenantOf($a);
        $tb = $this->tenantOf($b);
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate(['tenant_id' => $ta->id, 'amount_irr' => '100000', 'remaining_irr' => '100000', 'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'professional']);
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate(['tenant_id' => $tb->id, 'amount_irr' => '100000', 'remaining_irr' => '100000', 'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'free']);

        // Files: each shop writes only under its own folder; A's re-upload never touches B's logo.
        $this->setPlan($tb, 'basic');
        $this->actingAs($b)->post('/api/settings/logo', ['logo' => UploadedFile::fake()->image('b.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $bLogo = Storage::disk('local')->get("logos/{$tb->public_id}/v1.png");
        $this->setPlan($tb, 'free');
        foreach ([1, 2] as $n) {
            $this->actingAs($a)->post('/api/settings/logo', ['logo' => UploadedFile::fake()->image("a{$n}.jpg", 400, 300)], ['Accept' => 'application/json'])->assertOk();
        }
        $this->assertEqualsCanonicalizing(['logos/'.$ta->public_id, 'logos/'.$tb->public_id], Storage::disk('local')->directories('logos'));
        $this->assertCount(2, Storage::disk('local')->files("logos/{$ta->public_id}"));
        $this->assertSame($bLogo, Storage::disk('local')->get("logos/{$tb->public_id}/v1.png"));

        // Job: A's invoice SMS (queued job, run without a tenant context) charges A only and leaves no context behind.
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($a)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $sv = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750']], 'buyer' => ['name' => 'مشتری الف', 'mobile' => '09351234599']])->assertOk();
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', ['mode' => 'ISSUE_AND_SMS', 'version' => $sv->json('version'), 'idempotency_key' => 'k-iso-job-1', 'buyer' => ['name' => 'مشتری الف', 'mobile' => '09351234599']])->assertCreated();
        $msg = SmsMessage::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertSame($ta->id, $msg->tenant_id);
        $this->assertContains($msg->status, ['SENT', 'DELIVERED']);
        $left = fn ($t) => (string) SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->sum('remaining_irr');
        $this->assertLessThan(100000, (int) $left($ta));
        $this->assertSame('100000', $left($tb));
        $this->assertSame(0, SmsMessage::withoutGlobalScope('tenant')->where('tenant_id', $tb->id)->count());

        // Cached commercial config is shared, entitlements are not: after A (Professional) warmed every cache,
        // B (Free) still gets Free limits and A's customers stay invisible.
        $this->api('POST', '/api/customers', ['name' => 'مشتری حرفه‌ای'])->assertCreated();
        $this->get('/invoices')->assertOk();
        $this->actingAs($b)->get('/invoices')->assertOk();
        $this->assertSame(50, app(Entitlements::class)->limit($tb, 'invoices_per_month'));
        $this->assertNull(app(Entitlements::class)->limit($ta, 'invoices_per_month'));
        $this->api('GET', '/api/customers')->assertOk()->assertJsonCount(0, 'items');
    }
}
