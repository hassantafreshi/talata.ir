<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\MobileChange;
use App\Domain\Identity\OtpService;
use App\Support\Mobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Login-number change (M-23): two OTP steps in one session-scoped state machine.
 *  1) start           → OTP to the current number (re-authentication)
 *  2) verify-current  → confirms the holder of the current number
 *  3) request-new     → validate the target number, OTP to it
 *  4) confirm         → confirms the new number and swaps
 * The current-number OTP is itself the step-up, so no separate "recent login" gate is needed.
 */
class SecurityController extends BaseController
{
    private const RESEND = 'mch.resend_at';

    /** A current-number verification is good for this long and for this many different new numbers. */
    private const VERIFIED_FOR_SECONDS = 600;

    private const MAX_TARGETS = 3;

    public function startMobileChange(Request $request, OtpService $otp): JsonResponse
    {
        $user = $request->user();
        $result = $otp->request($user->mobile, $request->ip(), 'mch_old');
        $request->session()->put([
            'mch.cid' => $result['challenge_id'], 'mch.stage' => 'old', 'mch.old_ok' => false,
            self::RESEND => now()->getTimestamp() + $result['resend_after_seconds'],
        ]);
        $request->session()->forget(['mch.new', 'mch.old_at', 'mch.targets']);

        return response()->json([
            'stage' => 'old', 'masked' => Mobile::mask($user->mobile),
            'resend_after_seconds' => $result['resend_after_seconds'],
        ]);
    }

    public function verifyCurrentMobile(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        if ($request->session()->get('mch.stage') !== 'old') {
            throw new DomainError('MCH_FLOW', 'مرحله نامعتبر است. از ابتدا دوباره شروع کنید.', 409);
        }
        $cid = (string) $request->session()->get('mch.cid');
        $otp->verify($cid, $data['code'], $request->ip(), 'mch_old');
        $request->session()->put(['mch.old_ok' => true, 'mch.old_at' => now()->getTimestamp(), 'mch.targets' => [], 'mch.stage' => 'await_new']);
        $request->session()->forget('mch.cid');

        return response()->json(['stage' => 'await_new']);
    }

    public function requestNewMobile(Request $request, OtpService $otp, MobileChange $change): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
        $this->assertCurrentVerified($request);
        $new = $change->validateTarget($request->user(), $data['mobile']);
        // One verification of the current number may not be used to send codes to many different numbers.
        $targets = (array) $request->session()->get('mch.targets', []);
        if (! in_array($new, $targets, true)) {
            if (count($targets) >= self::MAX_TARGETS) {
                $request->session()->forget(['mch.old_ok', 'mch.old_at', 'mch.targets', 'mch.new', 'mch.cid']);
                $request->session()->put('mch.stage', 'expired');

                throw new DomainError('MCH_TOO_MANY_TARGETS', 'شماره‌های زیادی امتحان شد. برای امنیت، دوباره از ابتدا شروع کنید.', 429);
            }
            $targets[] = $new;
            $request->session()->put('mch.targets', $targets);
        }
        $result = $otp->request($new, $request->ip(), 'mch_new');
        $request->session()->put([
            'mch.new' => $new, 'mch.cid' => $result['challenge_id'], 'mch.stage' => 'new',
            self::RESEND => now()->getTimestamp() + $result['resend_after_seconds'],
        ]);

        return response()->json([
            'stage' => 'new', 'masked' => Mobile::mask($new),
            'resend_after_seconds' => $result['resend_after_seconds'],
        ]);
    }

    public function confirmMobileChange(Request $request, OtpService $otp, MobileChange $change): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $new = (string) $request->session()->get('mch.new');
        $this->assertCurrentVerified($request);
        if ($request->session()->get('mch.stage') !== 'new' || $new === '') {
            throw new DomainError('MCH_FLOW', 'مرحله نامعتبر است. از ابتدا دوباره شروع کنید.', 409);
        }
        $cid = (string) $request->session()->get('mch.cid');
        $otp->verify($cid, $data['code'], $request->ip(), 'mch_new');

        $user = $request->user();
        $change->apply($user, $new, $request->session()->getId());
        $request->session()->forget(['mch.cid', 'mch.stage', 'mch.old_ok', 'mch.old_at', 'mch.targets', 'mch.new', self::RESEND]);
        // Keep this device signed in: a fresh session id and a remember-me cookie carrying the new token.
        // Other devices were dropped in apply() and their old remember-me cookies no longer match.
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put('auth_at', now()->getTimestamp());

        return response()->json(['ok' => true, 'mobile_display' => Mobile::display($new)]);
    }

    private function assertCurrentVerified(Request $request): void
    {
        $at = (int) $request->session()->get('mch.old_at', 0);
        if (! $request->session()->get('mch.old_ok')) {
            throw new DomainError('MCH_FLOW', 'اول باید شماره فعلی را با کد تأیید کنید.', 409);
        }
        if (now()->getTimestamp() - $at > self::VERIFIED_FOR_SECONDS) {
            $request->session()->forget(['mch.old_ok', 'mch.old_at', 'mch.targets', 'mch.new', 'mch.cid']);

            throw new DomainError('MCH_FLOW', 'زمان تأیید شماره فعلی گذشت. دوباره از ابتدا شروع کنید.', 409);
        }
    }

    /**
     * «خروج از همه دستگاه‌های دیگر» — for a lost or shared phone: every other session ends and their
     * remember-me cookies stop working (new token); this device stays signed in with a fresh session.
     */
    public function signOutOtherDevices(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->setRememberToken(Str::random(60));
        $user->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        Auth::login($user, remember: true);
        $request->session()->put('auth_at', now()->getTimestamp());
        Audit::record('auth.signed_out_other_devices', $user, [], null, 'user');

        return response()->json(['ok' => true]);
    }
}
