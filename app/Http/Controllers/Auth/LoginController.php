<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\LoginService;
use App\Domain\Identity\OtpService;
use App\Domain\Identity\ProofOfWork;
use App\Http\Controllers\Controller;
use App\Support\Mobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function code(Request $request)
    {
        if (! $request->session()->has('otp.challenge_id')) {
            return redirect()->route('login');
        }

        return view('auth.code', [
            'masked' => $request->session()->get('otp.mobile_display'),
            'resendAt' => (int) $request->session()->get('otp.resend_at', 0),
        ]);
    }

    public function pow(Request $request, ProofOfWork $pow): JsonResponse
    {
        return response()->json($pow->issue($request->ip()))->header('Cache-Control', 'no-store');
    }

    public function requestOtp(Request $request, ProofOfWork $pow, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'pow_challenge' => ['required', 'string', 'max:64'],
            'pow_nonce' => ['required', 'string', 'max:20'],
            'website' => ['nullable', 'string', 'max:200'],
        ]);
        $mobile = Mobile::normalize($data['mobile']);
        if (! $mobile) {
            throw new DomainError('MOBILE_INVALID', 'شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹', 422, ['errors' => ['mobile' => ['شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹']]]);
        }
        if (! $pow->verify($data['pow_challenge'], $data['pow_nonce'], $request->ip())) {
            throw new DomainError('POW_INVALID', 'بررسی امنیتی کامل نشد. دوباره «دریافت کد» را بزنید.', 422);
        }
        if (! empty($data['website'])) {
            // Honeypot filled: answer like success but send nothing.
            Audit::record('auth.honeypot', null, [], null, 'system');
            $request->session()->put(['otp.challenge_id' => strtolower((string) Str::ulid()), 'otp.mobile_display' => Mobile::display($mobile), 'otp.resend_at' => time() + 90]);

            return response()->json(['next' => route('login.code')]);
        }

        $result = $otp->request($mobile, $request->ip());
        $request->session()->put([
            'otp.challenge_id' => $result['challenge_id'],
            'otp.mobile_display' => Mobile::display($mobile),
            'otp.resend_at' => time() + $result['resend_after_seconds'],
        ]);

        return response()->json(['next' => route('login.code'), 'resend_after_seconds' => $result['resend_after_seconds']]);
    }

    public function verifyOtp(Request $request, OtpService $otp, LoginService $login): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $challengeId = (string) $request->session()->get('otp.challenge_id');
        if ($challengeId === '') {
            throw new DomainError('OTP_EXPIRED', 'این کد دیگر معتبر نیست. کد تازه بگیرید.', 422);
        }
        $mobile = $otp->verify($challengeId, $data['code'], $request->ip());
        $result = $login->completeLogin($mobile);

        $request->session()->forget(['otp.challenge_id', 'otp.mobile_display', 'otp.resend_at']);
        Auth::login($result['user'], remember: true);
        $request->session()->regenerate();
        Audit::record('auth.login', $result['user'], ['new_tenant' => $result['is_new_tenant']], null, 'user');

        return response()->json(['next' => $result['is_new_tenant'] ? route('settings.business', ['welcome' => 1]) : route('invoices.new')]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
