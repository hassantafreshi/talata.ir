<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Support\Facades\DB;

/**
 * Changing the login mobile number. The number is the account identifier, so the change is gated by
 * two OTP ceremonies — one to the current number (proving the person holds it right now), then one to
 * the new number (proving they hold that too). The controller drives the two steps; this service owns
 * validation of the target number and the atomic swap plus its security after-effects.
 */
final class MobileChange
{
    /** Normalize and validate a proposed new login number, or throw a field error. */
    public function validateTarget(User $user, ?string $raw): string
    {
        $new = Mobile::normalize($raw);
        if (! $new) {
            throw new DomainError('MOBILE_INVALID', 'شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹', 422, ['errors' => ['mobile' => ['شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹']]]);
        }
        if ($new === $user->mobile) {
            throw new DomainError('MOBILE_SAME', 'این همان شماره فعلی شماست. برای تغییر، شماره دیگری وارد کنید.', 422, ['errors' => ['mobile' => ['این همان شماره فعلی شماست.']]]);
        }
        if (User::query()->where('mobile', $new)->exists()) {
            throw new DomainError('MOBILE_TAKEN', 'این شماره قبلاً حساب دارد. با همان شماره وارد شوید یا شماره دیگری انتخاب کنید.', 422, ['errors' => ['mobile' => ['این شماره قبلاً حساب دارد.']]]);
        }

        return $new;
    }

    /**
     * Swap the number and secure the account: every other device is signed out (the number is the
     * identity, and the holder of the old number must not keep a live session), and this device is
     * remembered for the new number. The current session is kept; the caller regenerates it.
     */
    public function apply(User $user, string $newMobile, string $currentSessionId): void
    {
        $old = $user->mobile;
        // Re-check under a row lock: two tabs must not both grab the same free number.
        DB::transaction(function () use ($user, $newMobile) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (User::query()->where('mobile', $newMobile)->where('id', '!=', $user->id)->exists()) {
                throw new DomainError('MOBILE_TAKEN', 'این شماره همین حالا حساب گرفت. شماره دیگری انتخاب کنید.', 409, ['errors' => ['mobile' => ['این شماره دیگر آزاد نیست.']]]);
            }
            $locked->mobile = $newMobile;
            $locked->save();
            $user->mobile = $newMobile;
        });

        // The old number must no longer unlock anything: drop every other session of this account.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete();
        TrustedDevice::remember($newMobile);

        Audit::record('auth.mobile_changed', $user, ['from_tail' => substr($old, -4), 'to_tail' => substr($newMobile, -4)], null, 'user');
    }
}
