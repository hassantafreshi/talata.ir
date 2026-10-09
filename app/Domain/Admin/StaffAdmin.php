<?php

namespace App\Domain\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\Passkey;
use App\Models\StaffUser;
use App\Support\Mobile;
use Illuminate\Support\Facades\DB;

/**
 * Staff and roles (docs/handoff/04_SCREENS_ADMIN.md A-10). Deactivation takes effect on the next
 * request (RequireStaff reloads the row every time). Nobody can lock the console out: you cannot
 * change your own role or deactivate yourself, and the last active admin stays an admin.
 */
final class StaffAdmin
{
    public function invite(StaffUser $actor, string $rawMobile, string $name, string $role): StaffUser
    {
        $mobile = Mobile::normalize($rawMobile);
        if (! $mobile) {
            throw new DomainError('MOBILE_INVALID', 'شماره موبایل درست نیست.', 422, ['errors' => ['mobile' => ['شماره موبایل درست نیست.']]]);
        }
        $this->assertRole($role);
        if (StaffUser::query()->where('mobile', $mobile)->exists()) {
            throw new DomainError('STAFF_EXISTS', 'این شماره قبلاً در فهرست کارکنان است.', 422, ['errors' => ['mobile' => ['این شماره قبلاً ثبت شده است.']]]);
        }
        $staff = StaffUser::query()->create(['mobile' => $mobile, 'name' => $name, 'role' => $role, 'active' => true]);
        Audit::record('admin.staff_invited', $staff, ['role' => $role], null, 'staff');

        return $staff;
    }

    public function update(StaffUser $actor, StaffUser $target, string $name, string $role, bool $active): StaffUser
    {
        $this->assertRole($role);

        return DB::transaction(function () use ($actor, $target, $name, $role, $active) {
            $target = StaffUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($target->id === $actor->id && ($role !== $target->role || ! $active)) {
                throw new DomainError('STAFF_SELF_CHANGE', 'نقش خودتان را نمی‌توانید تغییر دهید یا خودتان را غیرفعال کنید.', 422);
            }
            $losesAdmin = $target->isAdmin() && $target->active && ($role !== 'admin' || ! $active);
            if ($losesAdmin) {
                $admins = StaffUser::query()->where('role', 'admin')->where('active', true)->lockForUpdate()->get(['id'])->count();
                if ($admins <= 1) {
                    throw new DomainError('LAST_ADMIN', 'دست‌کم یک مدیر سامانه فعال باید بماند.', 422);
                }
            }
            $before = $target->only(['name', 'role', 'active']);
            $target->forceFill(['name' => $name, 'role' => $role, 'active' => $active])->save();
            Audit::record($active ? 'admin.staff_updated' : 'admin.staff_deactivated', $target, ['before' => $before, 'after' => $target->only(['name', 'role', 'active'])], null, 'staff');

            return $target;
        });
    }

    /** Lost device: another admin removes all of a staff member's passkeys; they bootstrap a new one by SMS. */
    public function resetPasskeys(StaffUser $actor, StaffUser $target, string $reason): int
    {
        if ($target->id === $actor->id) {
            throw new DomainError('STAFF_SELF_CHANGE', 'کلیدهای خودتان را از «حساب من» مدیریت کنید.', 422);
        }
        $n = Passkey::query()->where('owner_type', 'staff')->where('owner_id', $target->id)->delete();
        Audit::record('admin.staff_passkeys_reset', $target, ['removed' => $n, 'reason' => $reason], null, 'staff');

        return $n;
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, StaffUser::ROLES)) {
            throw new DomainError('ROLE_INVALID', 'نقش انتخاب‌شده معتبر نیست.', 422, ['errors' => ['role' => ['نقش را انتخاب کنید.']]]);
        }
    }
}
