<?php

use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use Carbon\CarbonImmutable;

if (! function_exists('toman')) {
    /** IRR integer string -> Persian grouped toman. */
    function toman(string|int|null $irr): string
    {
        return Money::toman($irr ?? '0');
    }
}

if (! function_exists('fa')) {
    function fa(string|int|null $value): string
    {
        return Digits::toPersian((string) $value);
    }
}

if (! function_exists('pct')) {
    /** Percentage number for display without trailing zeros: "10.5000" → "۱۰٫۵", "10" → "۱۰" (never "۱"). */
    function pct(string|int|float|null $value): string
    {
        return Digits::percent($value);
    }
}

if (! function_exists('jdate')) {
    function jdate(?DateTimeInterface $at, bool $time = false, ?string $tz = null): string
    {
        return Jalali::date($at, $tz ?? config('talata.timezone'), $time);
    }
}

if (! function_exists('jtime')) {
    function jtime(?DateTimeInterface $at, ?string $tz = null): string
    {
        return Jalali::time($at, $tz ?? config('talata.timezone'));
    }
}

if (! function_exists('jymd')) {
    /** Jalali date as Latin "YYYY/MM/DD" (date picker value format). */
    function jymd(?DateTimeInterface $at = null, ?string $tz = null): string
    {
        $d = CarbonImmutable::instance($at ?? now())->setTimezone($tz ?? config('talata.timezone'));
        [$jy, $jm, $jd] = Jalali::fromGregorian($d->year, $d->month, $d->day);

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

if (! function_exists('invno')) {
    /** Invoice number for display (Persian digits, safe with a letter prefix). */
    function invno(?string $number): string
    {
        return Digits::invoiceNumber($number);
    }
}
