<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\OtpService;
use App\Domain\Identity\ProofOfWork;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Domain\Identity\WebAuthn\WebAuthnService;
use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use App\Support\Mobile;
use App\Support\TechLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Staff sign-in: SMS code (same proof-of-work and limits as merchants) or passkey.
 * A code is sent only to an active staff mobile; the response is identical either way.
 */
class AuthController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::guard('staff')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', ['step' => $request->session()->has('admin.otp') ? 'code' : 'mobile']);
    }

    public function pow(Request $request, ProofOfWork $pow): JsonResponse
    {
        return response()->json($pow->issue($request->ip()));
    }

    public function requestOtp(Request $request, ProofOfWork $pow, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20'], 'pow_challenge' => ['required', 'string', 'max:64'], 'pow_nonce' => ['required', 'string', 'max:20']]);
        $mobile = Mobile::normalize($data['mobile']);
        if (! $mobile) {
            throw new DomainError('MOBILE_INVALID', 'شماره موبایل درست نیست.', 422, ['errors' => ['mobile' => ['شماره موبایل درست نیست.']]]);
        }
        if (! $pow->verify($data['pow_challenge'], $data['pow_nonce'], $request->ip())) {
            throw new DomainError('POW_INVALID', 'بررسی امنیتی کامل نشد. دوباره تلاش کنید.', 422);
        }
        $staff = StaffUser::query()->where('mobile', $mobile)->where('active', true)->first();
        $challengeId = $staff ? $otp->request($mobile, $request->ip())['challenge_id'] : strtolower((string) Str::ulid());
        if (! $staff) {
            TechLog::warning('admin', 'admin login requested for a non-staff mobile', ['mobile' => TechLog::scrub($mobile), 'ip' => $request->ip()]);
        }
        $request->session()->put('admin.otp', ['challenge' => $challengeId, 'mobile' => $mobile]);

        return response()->json(['ok' => true]);
    }

    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $state = $request->session()->get('admin.otp');
        if (! is_array($state)) {
            throw new DomainError('OTP_EXPIRED', 'کد تازه بگیرید.', 422);
        }
        $mobile = $otp->verify($state['challenge'], $data['code'], $request->ip());
        $staff = StaffUser::query()->where('mobile', $mobile)->where('active', true)->first();
        if (! $staff || $mobile !== $state['mobile']) {
            throw new DomainError('OTP_WRONG', 'کد درست نیست.', 422);
        }

        return $this->signIn($request, $staff, 'otp');
    }

    public function passkeyOptions(WebAuthnService $webauthn): JsonResponse
    {
        return response()->json($webauthn->loginOptions('staff'));
    }

    public function passkeyVerify(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $data = $request->validate(['credential' => ['required', 'array']]);
        try {
            $passkey = $webauthn->authenticate('staff', $data['credential'], fn ($pk) => StaffUser::query()->whereKey($pk->owner_id)->value('webauthn_handle'));
        } catch (WebAuthnException $e) {
            TechLog::warning('admin', 'admin passkey login rejected', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            throw new DomainError('PASSKEY_FAILED', 'ورود انجام نشد.', 422);
        }
        $staff = StaffUser::query()->whereKey($passkey->owner_id)->where('active', true)->first();
        if (! $staff) {
            throw new DomainError('PASSKEY_FAILED', 'ورود انجام نشد.', 422);
        }

        return $this->signIn($request, $staff, 'passkey');
    }

    private function signIn(Request $request, StaffUser $staff, string $method): JsonResponse
    {
        $request->session()->forget('admin.otp');
        Auth::guard('staff')->login($staff);
        $request->session()->regenerate();
        $request->session()->put(['staff.seen' => now()->getTimestamp(), 'staff.auth_at' => now()->getTimestamp()]);
        $staff->forceFill(['last_login_at' => now()])->save();
        Audit::record('admin.login', $staff, ['method' => $method], null, 'staff');

        return response()->json(['next' => route('admin.dashboard')]);
    }

    public function logout(Request $request)
    {
        Audit::record('admin.logout', Auth::guard('staff')->user(), [], null, 'staff');
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
