<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Technical log per service (kavenegar, payment gateway, quotes, queue …). Written to the
 * `tech` channel, which is viewable only in the administrator dashboard. Secrets are redacted.
 */
final class TechLog
{
    private const SECRET_KEYS = ['api_key', 'apikey', 'token', 'code', 'password', 'secret', 'authorization', 'cookie', 'otp', 'body'];

    public static function info(string $service, string $message, array $context = []): void
    {
        self::write('info', $service, $message, $context);
    }

    public static function warning(string $service, string $message, array $context = []): void
    {
        self::write('warning', $service, $message, $context);
    }

    public static function error(string $service, string $message, array $context = []): void
    {
        self::write('error', $service, $message, $context);
    }

    public static function write(string $level, string $service, string $message, array $context = []): void
    {
        Log::channel('tech')->log($level, $message, ['service' => $service] + self::redact($context));
    }

    public static function redact(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_array($v)) {
                $context[$k] = self::redact($v);
            } elseif (is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true)) {
                $context[$k] = '[redacted]';
            } elseif (is_string($v)) {
                $context[$k] = self::scrub($v);
            }
        }

        return $context;
    }

    /** Masks configured provider secrets and mobile numbers inside free text. */
    public static function scrub(string $text): string
    {
        foreach (array_filter([config('services.kavenegar.api_key')]) as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return preg_replace('/\b(09\d{2})\d{4}(\d{3})\b/', '$1****$2', $text) ?? $text;
    }
}
