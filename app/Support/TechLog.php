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
            if (is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true)) {
                $context[$k] = '[redacted]'; // whole value, whatever its type
            } elseif (is_array($v)) {
                $context[$k] = self::redact($v);
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

        // Persian/Arabic digits → Latin (spaces kept), then mask 09…, 989…, +989…, 00989… numbers.
        $text = strtr($text, array_combine(mb_str_split('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'), str_split('01234567890123456789')));

        return preg_replace('/(?<!\d)(\+98|0098|98|0)(9\d{2})\d{4}(\d{3})(?!\d)/', '$1$2****$3', $text) ?? $text;
    }
}
