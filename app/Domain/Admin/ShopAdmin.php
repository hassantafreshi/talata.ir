<?php

namespace App\Domain\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Plans\Entitlements;
use App\Models\FeatureOverride;
use App\Models\StaffUser;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff actions on one shop (docs/handoff/04_SCREENS_ADMIN.md A-03): suspension and feature
 * overrides. Every call carries a reason and is audited with the staff actor.
 */
final class ShopAdmin
{
    /** Suspension blocks the merchant panel (ResolveTenant); issued-invoice verification pages keep working. */
    public function suspend(Tenant $tenant, StaffUser $staff, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $reason) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (! $tenant->isActive()) {
                throw new DomainError('ALREADY_SUSPENDED', 'این فروشگاه همین حالا تعلیق است.', 409);
            }
            $tenant->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
            Audit::record('tenant.suspended', $tenant, ['reason' => $reason], $tenant->id, 'staff');

            return $tenant;
        });
    }

    public function unsuspend(Tenant $tenant, StaffUser $staff, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $reason) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($tenant->isActive()) {
                throw new DomainError('NOT_SUSPENDED', 'این فروشگاه تعلیق نیست.', 409);
            }
            $tenant->forceFill(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])->save();
            Audit::record('tenant.unsuspended', $tenant, ['reason' => $reason], $tenant->id, 'staff');

            return $tenant;
        });
    }

    /** Turns one capability on or off for this shop until $expiresAt, over whatever the plan says. */
    public function override(Tenant $tenant, StaffUser $staff, string $key, bool $enabled, CarbonImmutable $expiresAt, string $reason): FeatureOverride
    {
        if (! array_key_exists($key, Entitlements::OVERRIDABLE_FA)) {
            throw new DomainError('CAPABILITY_INVALID', 'این قابلیت را نمی‌توان جداگانه تنظیم کرد.', 422);
        }
        if ($expiresAt->lte(now()) || $expiresAt->gt(now()->addYear()->addDay())) {
            throw new DomainError('EXPIRY_INVALID', 'تاریخ پایان باید در آینده و حداکثر یک سال دیگر باشد.', 422);
        }

        return DB::transaction(function () use ($tenant, $staff, $key, $enabled, $expiresAt, $reason) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            // One live override per key: the newest replaces any earlier one.
            $this->active($tenant, $key)->update(['expires_at' => now()]);
            $row = FeatureOverride::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id, 'key' => $key, 'value' => ['enabled' => $enabled], 'expires_at' => $expiresAt,
                'reason' => $reason, 'created_by_staff' => $staff->id,
            ]);
            Audit::record('tenant.feature_override', $tenant, ['key' => $key, 'enabled' => $enabled, 'expires_at' => $expiresAt->toIso8601String(), 'reason' => $reason], $tenant->id, 'staff');

            return $row;
        });
    }

    public function endOverride(Tenant $tenant, StaffUser $staff, int $overrideId, string $reason): void
    {
        DB::transaction(function () use ($tenant, $overrideId, $reason) {
            $row = FeatureOverride::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereKey($overrideId)->lockForUpdate()->firstOrFail();
            if ($row->expires_at && $row->expires_at->lte(now())) {
                throw new DomainError('OVERRIDE_ENDED', 'این تنظیم قبلاً تمام شده است.', 409);
            }
            $row->forceFill(['expires_at' => now()])->save();
            Audit::record('tenant.feature_override_ended', $tenant, ['key' => $row->key, 'reason' => $reason], $tenant->id, 'staff');
        });
    }

    private function active(Tenant $tenant, string $key)
    {
        return FeatureOverride::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('key', $key)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
