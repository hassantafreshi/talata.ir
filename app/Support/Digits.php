<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/** Normalizes Persian/Arabic-Indic digits and separators so user input in any keyboard layout is accepted. */
final class Digits
{
    private const FA = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const AR = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function toLatin(?string $value): string
    {
        $value = (string) $value;
        $value = str_replace(self::FA, range(0, 9), $value);
        $value = str_replace(self::AR, range(0, 9), $value);
        // Persian decimal separator and thousands separators.
        $value = str_replace(['٫', '/'], '.', $value);
        $value = str_replace(['٬', ',', '،', ' ', "\u{200c}", "\u{00a0}"], '', $value);

        return trim($value);
    }

    public static function toPersian(string|int|null $value): string
    {
        return str_replace(range(0, 9), self::FA, (string) $value);
    }

    /** Groups an integer string with the Persian thousands separator and Persian digits. */
    public static function group(string $integer): string
    {
        $negative = str_starts_with($integer, '-');
        $digits = ltrim($integer, '-');
        $grouped = str_replace(',', '٬', strrev(implode(',', str_split(strrev($digits), 3))));

        return ($negative ? '−' : '').self::toPersian($grouped);
    }

    /**
     * Invoice number for display: Persian digits, and a left-to-right mark after a letter prefix (e.g. «ط»),
     * so «ط-05-0012» never shows reversed (digits after an Arabic letter otherwise take RTL order).
     */
    public static function invoiceNumber(?string $number): string
    {
        if ($number === null || $number === '') {
            return '';
        }

        return self::toPersian(preg_replace('/(\p{L}+)(?=[^\p{L}]|$)/u', "$1\u{200E}", $number));
    }

    /** Decimal percentage → Persian digits without trailing zeros ("10.5000" → "۱۰٫۵", "10" → "۱۰"). */
    public static function percent(string|int|float|null $value): string
    {
        $v = (string) BigDecimal::of(is_float($value) ? number_format($value, 4, '.', '') : (string) ($value ?? '0'))->strippedOfTrailingZeros();

        return self::toPersian(str_replace('.', '٫', $v));
    }
}
