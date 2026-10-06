<?php

namespace Tests\Feature;

use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Plans\PricingAdmin;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\PricingVersion;
use App\Models\StaffUser;
use Tests\TestCase;

/** Admin console price editor: docs/ADMIN_PRICING.md. */
class AdminPricingTest extends TestCase
{
    private function admin(string $role = 'admin'): StaffUser
    {
        $staff = StaffUser::query()->create(['mobile' => $role === 'admin' ? '09120000011' : '09120000012', 'name' => 'مدیر قیمت', 'role' => $role, 'active' => true]);
        $this->asStaff($staff);

        return $staff;
    }

    private function input(array $over = []): array
    {
        $c = app(PricingAdminFixture::class)->current();

        return array_replace_recursive(['plans' => [
            'basic' => ['monthly' => $c['plans']['basic']['monthly'], 'yearly' => $c['plans']['basic']['yearly']],
            'professional' => ['monthly' => $c['plans']['professional']['monthly'], 'yearly' => $c['plans']['professional']['yearly']],
        ], 'sms' => array_map(fn ($s) => $s['per_segment'], $c['sms'])], $over);
    }

    public function test_admin_publishes_new_prices_as_a_new_version_and_orders_keep_their_amount(): void
    {
        $merchant = $this->merchant();
        $pending = $this->actingAs($merchant)->api('POST', '/api/billing/orders', ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $order = BillingOrder::withoutGlobalScope('tenant')->latest('id')->first();
        $before = (string) $order->subtotal_irr;
        $versionBefore = app(CommercialConfig::class)->version();

        $staff = $this->admin();
        $this->get('/admin/pricing')->assertOk()->assertSee('قیمت پلن‌ها و پیامک')->assertSee('انتشار قیمت‌های جدید');
        $this->postJson('/admin/api/pricing', $this->input())->assertStatus(422)->assertJsonPath('code', 'NO_CHANGES');
        $this->postJson('/admin/api/pricing', $this->input(['plans' => ['basic' => ['monthly' => '۹۹۰٬۰۰۰']], 'sms' => ['basic' => '450', 'free' => '900']]))
            ->assertCreated()->assertJsonPath('version', $versionBefore + 1)->assertJsonCount(3, 'changes');

        $v = PricingVersion::query()->where('version', $versionBefore + 1)->firstOrFail();
        $this->assertSame($staff->id, $v->created_by_staff);
        $this->assertSame('990000', $v->payload['plans']['basic']['price_toman']['monthly']);
        $this->assertSame('450', $v->payload['sms_credit']['per_segment_toman']['basic']);
        $this->assertSame('0', (string) $v->payload['plans']['free']['price_toman']['monthly'], 'Free stays 0');
        $this->assertSame(1, AuditEvent::query()->where('event', 'pricing.published')->where('staff_id', $staff->id)->count());

        // The order created before the change keeps its amount; a new order uses the new price.
        $this->assertSame($before, (string) $order->fresh()->subtotal_irr);
        app()->forgetInstance(CommercialConfig::class);
        app()->forgetInstance(Entitlements::class);
        $this->actingAs($merchant)->api('POST', '/api/billing/orders', ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $this->assertSame('9900000', (string) BillingOrder::withoutGlobalScope('tenant')->latest('id')->first()->subtotal_irr);
        $this->get('/settings/sms')->assertOk()->assertSee('۹۰۰'); // Free merchant sees the Free per-segment price
        $this->get('/settings/plan')->assertOk()->assertSee('۹۹۰٬۰۰۰');
    }

    public function test_validation_typo_guard_restore_and_support_is_read_only(): void
    {
        $this->admin();
        $bad = $this->postJson('/admin/api/pricing', $this->input(['plans' => ['basic' => ['monthly' => '0', 'yearly' => 'abc']], 'sms' => ['free' => '-5']]))->assertStatus(422);
        $this->assertArrayHasKey('plans.basic.monthly', $bad->json('errors'));
        $this->assertArrayHasKey('plans.basic.yearly', $bad->json('errors'));
        $this->assertArrayHasKey('sms.free', $bad->json('errors'));
        $yearly = $this->postJson('/admin/api/pricing', $this->input(['plans' => ['professional' => ['yearly' => '99000000']]]))->assertStatus(422);
        $this->assertSame('قیمت سالانه نباید از ۱۲ برابر ماهانه بیشتر باشد.', $yearly->json('errors')['plans.professional.yearly'][0]);

        // A >50% change (e.g. a missing zero) needs an explicit second confirmation.
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '5000']]))->assertStatus(409)->assertJsonPath('code', 'CONFIRM_LARGE_CHANGE');
        $first = app(CommercialConfig::class)->version();
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '5000'], 'confirm_large' => true]))->assertCreated();
        $old = PricingVersion::query()->where('version', $first)->firstOrFail();
        app()->forgetInstance(CommercialConfig::class); // tests reuse one app between requests; production builds it per request
        $this->postJson("/admin/api/pricing/{$old->id}/restore")->assertCreated()->assertJsonPath('version', $first + 2);
        app()->forgetInstance(CommercialConfig::class);
        $this->assertSame($old->payload['sms_credit']['per_segment_toman'], app(CommercialConfig::class)->sms()['per_segment_toman']);

        $this->admin('support');
        $this->get('/admin/pricing')->assertOk()->assertSee('فقط مدیر ارشد می‌تواند قیمت‌ها را تغییر دهد.');
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '400']]))->assertForbidden();
    }

    public function test_publishing_prices_needs_a_recent_sign_in_and_the_right_role(): void
    {
        $staff = StaffUser::query()->create(['mobile' => '09120000013', 'name' => 'مالی', 'role' => 'finance', 'active' => true]);
        $this->asStaff($staff, 3600);
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '450']]))->assertStatus(403)->assertJsonPath('code', 'REAUTH_REQUIRED');
        $this->asStaff($staff);
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '450']]))->assertCreated();

        $ops = StaffUser::query()->create(['mobile' => '09120000014', 'name' => 'فنی', 'role' => 'ops', 'active' => true]);
        $this->asStaff($ops);
        $this->postJson('/admin/api/pricing', $this->input(['sms' => ['basic' => '460']]))->assertStatus(403)->assertJsonPath('code', 'STAFF_FORBIDDEN');
    }
}

/** Reads the active editable prices the same way the admin page does. */
class PricingAdminFixture
{
    public function current(): array
    {
        app()->forgetInstance(CommercialConfig::class);

        return app(PricingAdmin::class)->current();
    }
}
