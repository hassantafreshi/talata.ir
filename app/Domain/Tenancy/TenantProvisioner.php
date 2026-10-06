<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\Audit;
use App\Domain\Invoices\LayoutSettings;
use App\Models\InvoiceLayout;
use App\Models\Membership;
use App\Models\ShopProfile;
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

            return $tenant;
        });
    }
}
