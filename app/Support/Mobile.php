<?php

namespace App\Support;

/**
 * Iranian mobile numbers only. Anything else is rejected, which also blocks
 * international premium-rate destinations (SMS toll fraud).
 */
final class Mobile
{
    private const PATTERN = '/^09(0[0-5]|1\d|2[0-2]|3\d|41|9\d)\d{7}$/';

    public static function normalize(?string $raw): ?string
    {
        $value = Digits::toLatin($raw);
        $value = preg_replace('/[\s\-\(\)\.]/', '', $value) ?? '';
        if (str_starts_with($value, '+98')) {
            $value = '0'.substr($value, 3);
        } elseif (str_starts_with($value, '0098')) {
            $value = '0'.substr($value, 4);
        } elseif (str_starts_with($value, '98') && strlen($value) === 12) {
            $value = '0'.substr($value, 2);
        } elseif (str_starts_with($value, '9') && strlen($value) === 10) {
            $value = '0'.$value;
        }

        return preg_match(self::PATTERN, $value) ? $value : null;
    }

    public static function mask(?string $mobile): string
    {
        if (! $mobile || strlen($mobile) !== 11) {
            return '';
        }

        return substr($mobile, 0, 4).'•••'.substr($mobile, 7);
    }

    public static function display(?string $mobile): string
    {
        if (! $mobile) {
            return '';
        }

        return Digits::toPersian(substr($mobile, 0, 4).' '.substr($mobile, 4, 3).' '.substr($mobile, 7));
    }
}
