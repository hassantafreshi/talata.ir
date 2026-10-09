<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\Audit;
use App\Domain\Invoices\LayoutSettings;
use App\Models\InvoiceLayout;
use App\Models\Membership;
use App\Models\ShopProfile;
use App\Models\SmsCreditLot;
use App\Models\Tenant;
use App\Models\TenantBusinessType;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Creates a tenant with its owner on first verified login (no duplicate on retries). */
final class TenantProvisioner
{
    public function provisionFor(User $user): Tenant
    {
        return DB::transaction(function () use ($user) {
            $existing = Membership::query()->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            if ($existing) {
                return $existing->tenant;
            }
            $tenant = Tenant::create(['timezone' => config('talata.timezone'), 'status' => 'active']);
            Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'permissions' => array_keys(Membership::PERMISSIONS), 'status' => 'active']);
            app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
                ShopProfile::create(['socials' => []]);
                TenantBusinessType::create(['business_type' => 'GOLD_SHOP', 'enabled' => true, 'selected_at' => now()]);
                InvoiceLayout::create(['version' => 1, 'settings' => LayoutSettings::preset('simple_readable')]);
                Audit::record('tenant.created', $tenant, [], $tenant->id);
            });
            $this->seedStarterSmsCredit($tenant);

            return $tenant;
        });
    }

    /**
     * Test/staging convenience: a brand-new shop has no prepaid SMS credit, and the invoice SMS can be more
     * than two segments (so the free-yearly allowance may not cover it), which leaves it waiting for credit
     * and never sent. On non-production environments only, grant a small starter credit so invoice SMS can be
     * exercised end-to-end. Production stays at zero — real shops buy credit or use the free allowance.
     */
    private function seedStarterSmsCredit(Tenant $tenant): void
    {
        $toman = (int) config('talata.sms.starter_credit_toman');
        if ($toman <= 0 || app()->isProduction()) {
            return;
        }
        SmsCreditLot::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id, 'source' => 'PROVIDER_ADJUST', 'amount_irr' => (string) ($toman * 10), 'remaining_irr' => (string) ($toman * 10),
            'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'free', 'note' => 'اعتبار آزمایشی اولیه (محیط تست)',
        ]);
    }
}
