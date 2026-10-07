<?php

namespace Tests;

use App\Domain\Identity\LoginService;
use App\Models\Membership;
use App\Models\ShopProfile;
use App\Models\StaffUser;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Log writes use their own connection; keep them inside each test's transaction too (SQLite: one connection). */
    protected function connectionsToTransact()
    {
        return array_values(array_unique([config('database.default'), config('database.log_connection')]));
    }

    private int $mobileSeq = 1000000;

    protected function nextMobile(): string
    {
        return '0912'.str_pad((string) ($this->mobileSeq++), 7, '0', STR_PAD_LEFT);
    }

    /** A logged-in-ready merchant owner with a complete shop profile on the given plan. */
    protected function merchant(string $plan = 'free', bool $complete = true, ?string $mobile = null): User
    {
        $user = app(LoginService::class)->completeLogin($mobile ?? $this->nextMobile())['user'];
        $tenant = $this->tenantOf($user);
        if ($complete) {
            ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->update([
                'name' => 'طلافروشی آزمون', 'business_mobile' => '09121112233', 'address' => 'تهران، بازار بزرگ، پلاک ۱',
            ]);
        }
        if ($plan !== 'free') {
            $this->setPlan($tenant, $plan);
        }

        return $user;
    }

    protected function tenantOf(User $user): Tenant
    {
        return Membership::query()->where('user_id', $user->id)->where('status', 'active')->firstOrFail()->tenant;
    }

    protected function setPlan(Tenant $tenant, string $plan): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($tenant, $plan) {
            Subscription::query()->update(['status' => 'superseded']);
            Subscription::query()->forceCreate([
                'tenant_id' => $tenant->id, 'plan_code' => $plan, 'period' => 'monthly', 'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(), 'activated_by' => 'PROVIDER', 'status' => 'active',
            ]);
        });
    }

    /** Runs code inside the merchant's tenant context (as a request would). */
    protected function inTenant(User $user, callable $fn): mixed
    {
        $tenant = $this->tenantOf($user);
        $membership = Membership::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->first();
        $ctx = app(TenantContext::class);
        $ctx->set($tenant, $membership);
        try {
            return $fn($tenant);
        } finally {
            $ctx->clear();
        }
    }

    /**
     * Signs a staff member into the admin console. The admin area runs on its own session store
     * (UseAdminSession), so the data is put there; $authAgo = seconds since sign-in (step-up checks),
     * $method = how they signed in (an SMS-code session is limited when passkeys are mandatory).
     */
    protected function asStaff(StaffUser $staff, int $authAgo = 0, string $method = 'passkey'): static
    {
        config(['session.cookie' => config('talata.admin.session_cookie')]);
        if (app('session')->driver()->getName() !== config('session.cookie')) {
            app('session')->forgetDrivers();
        }

        return $this->actingAs($staff, 'staff')->withSession(['staff.seen' => now()->getTimestamp(), 'staff.auth_at' => now()->getTimestamp() - $authAgo, 'staff.auth_method' => $method]);
    }

    /** JSON request helper with the AJAX headers the app uses. */
    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->json($method, $uri, $data, ['X-Requested-With' => 'XMLHttpRequest']);
    }
}
