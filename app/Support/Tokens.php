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
}
