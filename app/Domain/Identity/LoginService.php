<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
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
            $user = User::query()->where('mobile', $mobile)->lockForUpdate()->first() ?? User::create(['mobile' => $mobile]);
            $user->forceFill(['last_login_at' => now()])->save();

            $invites = Membership::query()->where('invited_mobile', $mobile)->where('status', 'invited')->whereNull('user_id')->get();
            foreach ($invites as $invite) {
                $invite->update(['user_id' => $user->id, 'status' => 'active']);
                Audit::record('membership.accepted', $invite, [], $invite->tenant_id);
            }

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
