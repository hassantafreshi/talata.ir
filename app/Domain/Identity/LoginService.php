<?php

namespace App\Domain\Identity;

use App\Domain\Tenancy\TenantProvisioner;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** After a verified OTP: find/create the user, accept invitations, or provision a new shop. */
final class LoginService
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    /** @return array{user:User,is_new_tenant:bool} */
    public function completeLogin(string $mobile): array
    {
        return DB::transaction(function () use ($mobile) {
            // Serialize per number: two first logins at once (e.g. a «دستگاه آشنا» code and a normal code) must not
            // both miss the row lock and race to create the same user (unique violation → 500) or two shops.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['login:'.$mobile]);
            $user = User::query()->where('mobile', $mobile)->lockForUpdate()->first() ?? User::create(['mobile' => $mobile]);
            $user->forceFill(['last_login_at' => now()])->save();

            // Invites are never auto-accepted (an attacker could pre-invite a victim's number and
            // capture their data); the user accepts them explicitly in Settings.
            $hasActive = Membership::query()->where('user_id', $user->id)->where('status', 'active')->exists();
            $isNew = false;
            if (! $hasActive) {
                $this->provisioner->provisionFor($user);
                $isNew = true;
            }

            return ['user' => $user, 'is_new_tenant' => $isNew];
        });
    }
}
