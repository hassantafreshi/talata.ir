<?php

namespace App\Domain\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * «This number has signed in on this device before» — an HttpOnly cookie (encrypted by EncryptCookies)
 * holding an HMAC of the mobile. It only selects a separate OTP allowance (OtpService); it never signs
 * anyone in. One number per device: the latest sign-in wins.
 */
final class TrustedDevice
{
    public const COOKIE = 'talata_td';

    public static function value(string $mobile): string
    {
        return 'v1.'.hash_hmac('sha256', 'trusted-device|'.$mobile, (string) config('app.key'));
    }

    public static function matches(Request $request, string $mobile): bool
    {
        return hash_equals(self::value($mobile), (string) $request->cookie(self::COOKIE, ''));
    }

    public static function remember(string $mobile): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, self::value($mobile), 60 * 24 * 365, '/', null, null, true, false, 'lax'));
    }
}
