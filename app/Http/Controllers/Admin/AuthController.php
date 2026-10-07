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
use App\Support\Digits;
use App\Support\Mobile;
use App\Support\TechLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff sign-in: SMS code (same proof-of-work and limits as merchants) or passkey.
 * A code is sent only to an active staff mobile; the response is identical either way.
 */
class AuthController extends Controller
{
    public function show(Request $request)
    {
        // ?reauth=1: step-up sign-in for a dangerous action; ?back=/admin/... returns there afterwards.
        $back = (string) $request->query('back', '');
        if (preg_match('#^/admin(/[A-Za-z0-9/_-]*)?$#', $back)) {
            $request->session()->put('admin.back', $back);
        }
        if (Auth::guard('staff')->check() && ! $request->boolean('reauth')) {
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
        // Identical answer for every number (no staff enumeration): limits, cooldowns and locks
        // of the staff ceremony are applied but never revealed; unknown numbers get a decoy.
        $staff = StaffUser::query()->where('mobile', $mobile)->where('active', true)->first();
        $state = ['challenge' => null, 'mobile' => $mobile, 'decoy' => true, 'tries' => 0, 'at' => now()->getTimestamp()];
        if ($staff) {
            try {
                $state = ['challenge' => $otp->request($mobile, $request->ip(), 'staff')['challenge_id'], 'decoy' => false] + $state;
            } catch (DomainError $e) {
                TechLog::warning('admin', 'admin code not sent', ['reason' => $e->codeName, 'ip' => $request->ip()]);
                $previous = $request->session()->get('admin.otp');
                if (is_array($previous) && ($previous['mobile'] ?? null) === $mobile && ! ($previous['decoy'] ?? true)) {
                    $state = $previous; // keep the code that is still valid
                }
            }
        } else {
            TechLog::warning('admin', 'admin login requested for a non-staff mobile', ['mobile' => TechLog::scrub($mobile), 'ip' => $request->ip()]);
        }
        $request->session()->put('admin.otp', $state);

        return response()->json(['ok' => true]);
    }

    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $state = $request->session()->get('admin.otp');
        if (! is_array($state)) {
            throw new DomainError('OTP_EXPIRED', 'کد تازه بگیرید.', 422);
        }
        if ($state['decoy'] ?? true) {
            $this->decoyVerify($request, $state);
        }
        $mobile = $otp->verify($state['challenge'], $data['code'], $request->ip(), 'staff');
        $staff = StaffUser::query()->where('mobile', $mobile)->where('active', true)->first();
        if (! $staff || $mobile !== $state['mobile']) {
            throw new DomainError('OTP_WRONG', 'کد درست نیست.', 422);
        }

        return $this->signIn($request, $staff, 'otp');
    }

    /** Mirrors OtpService::verify outcomes for a number that never received a code. */
    private function decoyVerify(Request $request, array $state): never
    {
        $cfg = config('talata.otp');
        if (RateLimiter::tooManyAttempts('otp:verify:'.$request->ip(), $cfg['verify_per_ip_minute'])) {
            throw new DomainError('OTP_VERIFY_RATE', 'تعداد تلاش زیاد است. یک دقیقه صبر کنید.', 429);
        }
        RateLimiter::hit('otp:verify:'.$request->ip(), 60);
        if (now()->getTimestamp() - $state['at'] > $cfg['ttl_seconds'] || $state['tries'] >= $cfg['max_attempts']) {
            throw new DomainError('OTP_EXPIRED', 'این کد دیگر معتبر نیست. کد تازه بگیرید.', 422);
        }
        $state['tries']++;
        $request->session()->put('admin.otp', $state);
        if ($state['tries'] >= $cfg['max_attempts']) {
            throw new DomainError('OTP_LOCKED', 'تلاش‌های ناموفق زیاد شد. '.Digits::toPersian((string) $cfg['lockout_minutes']).' دقیقه دیگر دوباره امتحان کنید.', 429);
        }
        $left = $cfg['max_attempts'] - $state['tries'];

        throw new DomainError('OTP_WRONG', 'کد درست نیست. یک بار دیگر به پیامک نگاه کنید ('.Digits::toPersian((string) $left).' تلاش دیگر).', 422, ['attempts_left' => $left]);
    }

    public function passkeyOptions(WebAuthnService $webauthn): JsonResponse
    {
        return response()->json($webauthn->loginOptions('staff'));
    }

    public function passkeyVerify(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $data = $request->validate(WebAuthnService::rules(false));
        try {
            $passkey = $webauthn->authenticate('staff', $data['credential'], fn ($pk) => StaffUser::query()->whereKey($pk->owner_id)->value('webauthn_handle'));
        } catch (WebAuthnException $e) {
            TechLog::warning('admin', 'admin passkey login rejected', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            throw new DomainError('PASSKEY_FAILED', 'ورود انجام نشد. (کد: '.$e->reasonCode().')', 422, ['reason' => $e->reasonCode()]);
        }
        $staff = StaffUser::query()->whereKey($passkey->owner_id)->where('active', true)->first();
        if (! $staff) {
            throw new DomainError('PASSKEY_FAILED', 'ورود انجام نشد. (کد: '.$e->reasonCode().')', 422, ['reason' => $e->reasonCode()]);
        }

        return $this->signIn($request, $staff, 'passkey');
    }

    private function signIn(Request $request, StaffUser $staff, string $method): JsonResponse
    {
        $request->session()->forget('admin.otp');
        Auth::guard('staff')->login($staff);
        $request->session()->regenerate();
        // The method matters: with TALATA_ADMIN_REQUIRE_PASSKEY an SMS sign-in only bootstraps the first passkey.
        $request->session()->put(['staff.seen' => now()->getTimestamp(), 'staff.auth_at' => now()->getTimestamp(), 'staff.auth_method' => $method]);
        $staff->forceFill(['last_login_at' => now()])->save();
        Audit::record('admin.login', $staff, ['method' => $method], null, 'staff');
        $back = $request->session()->pull('admin.back');

        return response()->json(['next' => $back ? url($back) : route('admin.dashboard')]);
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
