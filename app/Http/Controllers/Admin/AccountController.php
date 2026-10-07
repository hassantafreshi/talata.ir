<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Domain\Identity\WebAuthn\WebAuthnService;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Support\TechLog;
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

    /**
     * Adding or removing keys once a key exists needs a passkey sign-in: otherwise someone holding only the
     * SMS code could enrol their own key (persistent access) or delete the owner's. The first key may be
     * added from an SMS session (bootstrap); lost devices are reset by another admin (/admin/staff).
     */
    private function requirePasskeySession(Request $request): void
    {
        if (config('talata.admin.require_passkey') && $request->session()->get('staff.auth_method') !== 'passkey'
            && Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->exists()) {
            throw new DomainError('PASSKEY_SIGNIN_REQUIRED', 'برای تغییر کلیدهای عبور، با یکی از کلیدهای فعلی وارد شوید. اگر دستگاه را گم کرده‌اید، مدیر دیگری کلیدهای شما را از «کارکنان و دسترسی» بازنشانی کند.', 403, ['login' => route('admin.login', ['reauth' => 1])]);
        }
    }

    public function show()
    {
        return view('admin.account', ['staff' => $this->staff(), 'passkeys' => Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->get()]);
    }

    public function options(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);
        $this->requirePasskeySession($request);

        return response()->json($webauthn->registrationOptions($this->staff(), 'staff', $this->staff()->name));
    }

    public function store(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);
        $this->requirePasskeySession($request);
        $data = $request->validate(WebAuthnService::rules(true));
        try {
            $pk = $webauthn->register($this->staff(), 'staff', $data['credential'], 'کلید مدیر');
        } catch (WebAuthnException $e) {
            TechLog::warning('admin', 'admin passkey registration rejected', ['reason' => $e->getMessage()]);

            throw new DomainError('PASSKEY_REGISTER_FAILED', 'فعال‌سازی انجام نشد. (کد: '.$e->reasonCode().')', 422, ['reason' => $e->reasonCode()]);
        }
        Audit::record('admin.passkey_registered', $this->staff(), ['passkey' => $pk->id], null, 'staff');

        return response()->json(['id' => $pk->id], 201);
    }

    public function destroy(Request $request, int $passkey): JsonResponse
    {
        $this->requirePasskeySession($request);
        Passkey::query()->where('owner_type', 'staff')->where('owner_id', $this->staff()->id)->whereKey($passkey)->firstOrFail()->delete();
        Audit::record('admin.passkey_removed', $this->staff(), ['passkey' => $passkey], null, 'staff');

        return response()->json(['ok' => true]);
    }
}
