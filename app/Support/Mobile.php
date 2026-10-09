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

    /**
     * Every Iranian mobile in free text: +98 9…, 0098 9…, 98 9…, 09… or 9… with exactly the right number of
     * digits, Persian/Arabic digits, any separators — or none: «0912123456709351234567» is two numbers.
     * Separators are dropped and the digit stream is read left to right, longest valid form first.
     * Returns [unique normalised 09… numbers in order, leftover fragments that are not a mobile].
     * Mirrored by resources/js/lib/mobiles.js; shared examples in tests/fixtures/mobile-extract-vectors.json.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function extractAll(?string $raw): array
    {
        $stream = preg_replace('/[^0-9+]/', '', Digits::toLatin((string) $raw)) ?? '';
        $found = [];
        $invalid = [];
        $junk = '';
        $n = strlen($stream);
        $i = 0;
        while ($i < $n) {
            $match = null;
            foreach ([['+98', 13], ['0098', 14], ['09', 11], ['98', 12], ['9', 10]] as [$prefix, $len]) {
                if (substr($stream, $i, strlen($prefix)) !== $prefix || $i + $len > $n) {
                    continue;
                }
                $candidate = '0'.substr($stream, $i + $len - 10, 10);
                if (preg_match(self::PATTERN, $candidate) && ! str_contains(substr($stream, $i + 1, $len - 1), '+')) {
                    $match = [$candidate, $len];
                    break;
                }
            }
            if ($match === null) {
                $junk .= $stream[$i++];

                continue;
            }
            if ($junk !== '') {
                $invalid[] = $junk;
                $junk = '';
            }
            $found[$match[0]] = true;
            $i += $match[1];
        }
        if ($junk !== '') {
            $invalid[] = $junk;
        }

        return [array_keys($found), $invalid];
    }

    /** True only for an already-normalized 09… Iranian mobile (no parsing: the exact stored/queued value). */
    public static function isIranian(?string $mobile): bool
    {
        return $mobile !== null && preg_match(self::PATTERN, $mobile) === 1;
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
