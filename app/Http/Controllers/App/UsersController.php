<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\Membership;
use App\Models\User;
use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsersController extends BaseController
{
    public function index()
    {
        $members = Membership::query()->where('tenant_id', $this->tenant()->id)->whereIn('status', ['active', 'invited'])->with('user')->orderByRaw("role = 'owner' DESC")->orderBy('id')->get();

        $tenant = $this->tenant();

        return view('app.users', [
            'members' => $members, 'me' => $this->membership(),
            'canRestrict' => $this->ent()->can($tenant, 'team.permissions_edit'), 'limit' => $this->ent()->limitOr($tenant, 'team_members', 10),
        ]);
    }

    private function find(int|string $id): Membership
    {
        return Membership::query()->where('tenant_id', $this->tenant()->id)->whereKey($id)->firstOrFail();
    }

    private function permissions(array $input): array
    {
        $perms = Membership::normalize($input);
        if (! $perms) {
            throw new DomainError('PERMISSIONS_EMPTY', 'دست‌کم یک دسترسی را برای همکار انتخاب کنید.', 422, ['errors' => ['permissions' => ['دست‌کم یک دسترسی را انتخاب کنید.']]]);
        }

        return $perms;
    }

    public function invite(Request $request)
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20'], 'permissions' => ['nullable', 'array']]);
        $mobile = Mobile::normalize($data['mobile']);
        if (! $mobile) {
            throw new DomainError('VALIDATION', 'شماره موبایل درست نیست.', 422, ['errors' => ['mobile' => ['شماره موبایل درست نیست.']]]);
        }
        $tenantId = $this->tenant()->id;
        if (Membership::query()->where('tenant_id', $tenantId)->where('status', '!=', 'removed')->where(fn ($q) => $q->where('invited_mobile', $mobile)->orWhereHas('user', fn ($u) => $u->where('mobile', $mobile)))->exists()) {
            throw new DomainError('ALREADY_MEMBER', 'این شماره قبلاً عضو یا دعوت شده است.', 409);
        }
        $limit = $this->ent()->limitOr($this->tenant(), 'team_members', 10);
        if ($limit !== null && Membership::query()->where('tenant_id', $tenantId)->whereIn('status', ['active', 'invited'])->count() >= $limit) {
            throw new DomainError('MEMBER_LIMIT', 'حداکثر '.Digits::toPersian((string) $limit).' کاربر برای هر فروشگاه.', 422);
        }
        // Full access by default; a chosen subset only where the plan allows restricting access.
        $permissions = $this->ent()->can($this->tenant(), 'team.permissions_edit') && isset($data['permissions'])
            ? $this->permissions($data['permissions']) : Membership::allPermissions();
        // Always pending: the invited person must accept in their own Settings.
        $m = Membership::create([
            'tenant_id' => $tenantId, 'user_id' => null, 'invited_mobile' => $mobile, 'role' => 'member',
            'permissions' => $permissions, 'status' => 'invited', 'invited_by' => auth()->id(),
        ]);
        Audit::record('membership.invited', $m, ['mobile_tail' => substr($mobile, -4)]);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, int $membership)
    {
        $m = $this->find($membership);
        if ($m->isOwner()) {
            throw new DomainError('OWNER_FIXED', 'دسترسی مالک قابل تغییر نیست.', 422);
        }
        $this->ent()->assertCan($this->tenant(), 'team.permissions_edit', 'تعیین سطح دسترسی همکاران در پلن پایه و حرفه‌ای است. در پلن رایگان همه همکاران دسترسی کامل دارند.');
        $m->update(['permissions' => $this->permissions((array) $request->input('permissions', []))]);
        Audit::record('membership.permissions_changed', $m, ['permissions' => $m->permissions]);

        return response()->json(['ok' => true]);
    }

    public function remove(int $membership)
    {
        $m = $this->find($membership);
        if ($m->isOwner()) {
            throw new DomainError('OWNER_FIXED', 'مالک فروشگاه قابل حذف نیست.', 422);
        }
        $m->update(['status' => 'removed']);
        if ($m->user_id) {
            DB::table('sessions')->where('user_id', $m->user_id)->delete();
        }
        Audit::record('membership.removed', $m);

        return response()->json(['ok' => true]);
    }

    /** Invites addressed to the logged-in user's mobile (any tenant). */
    private function myInvite(int $membership): Membership
    {
        return Membership::query()->whereKey($membership)->where('status', 'invited')->whereNull('user_id')
            ->where('invited_mobile', auth()->user()->mobile)->firstOrFail();
    }

    public function accept(Request $request, int $membership)
    {
        $m = $this->myInvite($membership);
        if (Membership::query()->where('tenant_id', $m->tenant_id)->where('user_id', auth()->id())->where('status', 'active')->exists()) {
            $m->update(['status' => 'removed']);

            return response()->json(['ok' => true]);
        }
        $m->update(['user_id' => auth()->id(), 'status' => 'active']);
        Audit::record('membership.accepted', $m, [], $m->tenant_id, 'user');
        $request->session()->put('tenant_id', $m->tenant_id);

        return response()->json(['ok' => true, 'next' => route('home')]);
    }

    public function decline(int $membership)
    {
        $m = $this->myInvite($membership);
        $m->update(['status' => 'removed']);
        Audit::record('membership.declined', $m, [], $m->tenant_id, 'user');

        return response()->json(['ok' => true]);
    }

    public function switchTenant(Request $request, int $membership)
    {
        $m = Membership::query()->whereKey($membership)->where('user_id', auth()->id())->where('status', 'active')->firstOrFail();
        $request->session()->put('tenant_id', $m->tenant_id);

        return response()->json(['ok' => true, 'next' => route('home')]);
    }
}
