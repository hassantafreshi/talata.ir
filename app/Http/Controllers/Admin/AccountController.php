<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Domain\Identity\WebAuthn\WebAuthnService;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The signed-in staff member's own passkeys. */
class AccountController extends Controller
{
    private function staff()
    {
        return Auth::guard('staff')->user();
    }

    private function requireRecentLogin(Request $request): void
    {
        if (now()->getTimestamp() - (int) $request->session()->get('staff.auth_at', 0) > config('talata.webauthn.recent_auth_minutes') * 60) {
            throw new DomainError('REAUTH_REQUIRED', 'برای افزودن کلید، دوباره وارد شوید.', 403);
        }
    }

    public function show()
    {
        return view('admin.account', ['staff' => $this->staff(), 'passkeys' => Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->get()]);
    }

    public function options(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);

        return response()->json($webauthn->registrationOptions($this->staff(), 'staff', $this->staff()->name));
    }

    public function store(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);
        $data = $request->validate(WebAuthnService::rules(true));
        try {
            $pk = $webauthn->register($this->staff(), 'staff', $data['credential'], 'کلید مدیر');
        } catch (WebAuthnException) {
            throw new DomainError('PASSKEY_REGISTER_FAILED', 'فعال‌سازی انجام نشد.', 422);
        }
        Audit::record('admin.passkey_registered', $this->staff(), ['passkey' => $pk->id], null, 'staff');

        return response()->json(['id' => $pk->id], 201);
    }

    public function destroy(int $passkey): JsonResponse
    {
        Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->whereKey($passkey)->firstOrFail()->delete();
        Audit::record('admin.passkey_removed', $this->staff(), ['passkey' => $passkey], null, 'staff');

        return response()->json(['ok' => true]);
    }
}
