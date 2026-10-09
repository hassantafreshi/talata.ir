<?php

namespace App\Support;

/** High-entropy URL-safe tokens for public capabilities (verification, share, payment result). */
final class Tokens
{
    public static function make(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Stored lookup key; the raw token never hits an index or a log. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(?string $token): bool
    {
        return is_string($token) && (bool) preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token);
    }

    /**
     * Short share code for links sent by SMS (owner decision 2026-10-07: «/i/123456789ABCDF»). Letters and digits
     * only, from a CSPRNG: 14 base-62 characters ≈ 83 bits — far beyond guessing behind the per-IP limit, while
     * the SMS stays short. Only for share links; the printed QR verification token keeps its full 256 bits.
     */
    public static function shareCode(int $length = self::SHARE_CODE_LENGTH): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, 61)];
        }

        return $code;
    }

    public const SHARE_CODE_LENGTH = 14;

    /** A share link token: the short code, or a long token from links sent before short codes existed. */
    public static function isShareToken(?string $token): bool
    {
        return is_string($token) && ((bool) preg_match('/^[A-Za-z0-9]{'.self::SHARE_CODE_LENGTH.'}$/', $token) || self::isWellFormed($token));
    }
}
