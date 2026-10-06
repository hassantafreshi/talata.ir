<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Domain\Identity\WebAuthn\WebAuthnService;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Support\TechLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Merchant sign-in with a passkey (fingerprint / face / device lock). SMS login stays available. */
class PasskeyLoginController extends Controller
{
    public function options(WebAuthnService $webauthn): JsonResponse
    {
        return response()->json($webauthn->loginOptions('user'));
    }

    public function verify(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $data = $request->validate(['credential' => ['required', 'array']]);
        try {
            $passkey = $webauthn->authenticate('user', $data['credential'], fn ($pk) => User::query()->whereKey($pk->owner_id)->value('webauthn_handle'));
        } catch (WebAuthnException $e) {
            TechLog::warning('auth', 'passkey login rejected', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);
            Audit::record('auth.passkey_failed', null, ['reason' => $e->getMessage()], null, 'system');

            throw new DomainError('PASSKEY_FAILED', 'ورود با اثر انگشت انجام نشد. دوباره امتحان کنید یا با کد پیامکی وارد شوید.', 422);
        }
        $user = User::query()->findOrFail($passkey->owner_id);
        if (! Membership::query()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            throw new DomainError('PASSKEY_FAILED', 'این حساب فعال نیست. با کد پیامکی وارد شوید.', 422);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put('auth_at', now()->getTimestamp());
        Audit::record('auth.passkey_login', $user, ['passkey' => $passkey->id]);

        return response()->json(['next' => route('invoices.new')]);
    }
}
