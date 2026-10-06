<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\Membership;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsersController extends BaseController
{
    public function index()
    {
        $members = Membership::query()->where('tenant_id', $this->tenant()->id)->whereIn('status', ['active', 'invited'])->with('user')->orderByRaw("role = 'owner' DESC")->orderBy('id')->get();

        return view('app.users', ['members' => $members, 'me' => $this->membership(), 'permissions' => Membership::PERMISSIONS]);
    }

    private function find(int|string $id): Membership
    {
        return Membership::query()->where('tenant_id', $this->tenant()->id)->whereKey($id)->firstOrFail();
    }

    private function permissions(array $input): array
    {
        return array_values(array_intersect(array_keys(Membership::PERMISSIONS), array_map('strval', $input)));
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
        if (Membership::query()->where('tenant_id', $tenantId)->whereIn('status', ['active', 'invited'])->count() >= 10) {
            throw new DomainError('MEMBER_LIMIT', 'حداکثر ۱۰ کاربر برای هر فروشگاه.', 422);
        }
        $user = User::query()->where('mobile', $mobile)->first();
        $m = Membership::create([
            'tenant_id' => $tenantId, 'user_id' => $user?->id, 'invited_mobile' => $mobile, 'role' => 'member',
            'permissions' => $this->permissions($data['permissions'] ?? ['invoice.issue']), 'status' => $user ? 'active' : 'invited', 'invited_by' => auth()->id(),
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
}
