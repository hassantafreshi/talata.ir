<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\StaffAdmin;
use App\Models\Passkey;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff and roles (docs/handoff/04_SCREENS_ADMIN.md A-10). */
class StaffController extends AdminController
{
    public function index()
    {
        $staff = StaffUser::query()->orderByDesc('active')->orderBy('id')->get();

        return view('admin.staff', [
            'staff' => $staff,
            'passkeys' => Passkey::query()->where('owner_type', 'staff')->selectRaw('owner_id, count(*) c')->groupBy('owner_id')->pluck('c', 'owner_id'),
            'roles' => StaffUser::ROLES, 'permissions' => StaffUser::PERMISSIONS,
            'me' => $this->staff(), 'canManage' => $this->staff()->allows('staff.manage'),
            'requirePasskey' => (bool) config('talata.admin.require_passkey'),
        ]);
    }

    public function store(Request $request, StaffAdmin $admin): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'min:2', 'max:60'], 'role' => ['required', 'string', 'max:20']]);
        $staff = $admin->invite($this->staff(), $data['mobile'], trim(strip_tags($data['name'])), $data['role']);

        return response()->json(['id' => $staff->id, 'message_fa' => 'همکار اضافه شد. با همین شماره از صفحه ورود مدیریت وارد می‌شود و باید کلید عبور اضافه کند.'], 201);
    }

    public function resetPasskeys(Request $request, int $staff, StaffAdmin $admin): JsonResponse
    {
        $n = $admin->resetPasskeys($this->staff(), StaffUser::query()->findOrFail($staff), $this->reason($request));

        return response()->json(['message_fa' => $n ? 'کلیدهای عبور حذف شد؛ این همکار با کد پیامکی وارد می‌شود و کلید تازه اضافه می‌کند.' : 'کلید عبوری نداشت.']);
    }

    public function update(Request $request, int $staff, StaffAdmin $admin): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60'], 'role' => ['required', 'string', 'max:20'], 'active' => ['required', 'boolean']]);
        $admin->update($this->staff(), StaffUser::query()->findOrFail($staff), trim(strip_tags($data['name'])), $data['role'], (bool) $data['active']);

        return response()->json(['message_fa' => $data['active'] ? 'ذخیره شد.' : 'غیرفعال شد؛ دسترسی او از همین لحظه قطع است.']);
    }
}
