<?php

namespace App\Http\Controllers\App;

use App\Domain\DomainError;
use App\Domain\Identity\MobileChange;
use App\Domain\Identity\OtpService;
use App\Support\Mobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function startMobileChange(Request $request, OtpService $otp): JsonResponse
    {
        $user = $request->user();
        $result = $otp->request($user->mobile, $request->ip(), 'mch_old');
        $request->session()->put([
            'mch.cid' => $result['challenge_id'], 'mch.stage' => 'old', 'mch.old_ok' => false,
            self::RESEND => time() + $result['resend_after_seconds'],
        ]);
        $request->session()->forget('mch.new');

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
        $request->session()->put(['mch.old_ok' => true, 'mch.stage' => 'await_new']);
        $request->session()->forget('mch.cid');

        return response()->json(['stage' => 'await_new']);
    }

    public function requestNewMobile(Request $request, OtpService $otp, MobileChange $change): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
        if (! $request->session()->get('mch.old_ok')) {
            throw new DomainError('MCH_FLOW', 'اول باید شماره فعلی را با کد تأیید کنید.', 409);
        }
        $new = $change->validateTarget($request->user(), $data['mobile']);
        $result = $otp->request($new, $request->ip(), 'mch_new');
        $request->session()->put([
            'mch.new' => $new, 'mch.cid' => $result['challenge_id'], 'mch.stage' => 'new',
            self::RESEND => time() + $result['resend_after_seconds'],
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
        if (! $request->session()->get('mch.old_ok') || $request->session()->get('mch.stage') !== 'new' || $new === '') {
            throw new DomainError('MCH_FLOW', 'مرحله نامعتبر است. از ابتدا دوباره شروع کنید.', 409);
        }
        $cid = (string) $request->session()->get('mch.cid');
        $otp->verify($cid, $data['code'], $request->ip(), 'mch_new');

        $change->apply($request->user(), $new, $request->session()->getId());
        $request->session()->forget(['mch.cid', 'mch.stage', 'mch.old_ok', 'mch.new', self::RESEND]);
        // Keep this device signed in with a fresh session; other devices were dropped in apply().
        $request->session()->regenerate();
        $request->session()->put('auth_at', now()->getTimestamp());

        return response()->json(['ok' => true, 'mobile_display' => Mobile::display($new)]);
    }
}
