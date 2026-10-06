<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Gregorian <-> Jalali (Solar Hijri) conversion, formatting and month boundaries in a timezone. */
final class Jalali
{
    /** @return array{0:int,1:int,2:int} */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
        $sal = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $sal[$gm]; $gm++) {
            $gd -= $sal[$gm];
        }

        return [$gy, $gm, $gd];
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        [$gy, $gm, $gd] = self::toGregorian($jy + 1, 1, 1);
        $nextNowruz = CarbonImmutable::create($gy, $gm, $gd);
        [$gy2, $gm2, $gd2] = self::toGregorian($jy, 12, 1);

        return (int) CarbonImmutable::create($gy2, $gm2, $gd2)->diffInDays($nextNowruz);
    }

    /** Half-open [start, end) of the Jalali month containing $at, in $tz, returned in UTC. */
    public static function monthBounds(DateTimeInterface $at, string $tz): array
    {
        $local = CarbonImmutable::instance($at)->setTimezone($tz);
        [$jy, $jm] = self::fromGregorian($local->year, $local->month, $local->day);
        $start = self::startOf($jy, $jm, $tz);
        [$ny, $nm] = $jm === 12 ? [$jy + 1, 1] : [$jy, $jm + 1];

        return [$start->utc(), self::startOf($ny, $nm, $tz)->utc(), $jy, $jm];
    }

    /** [start, end) of the Jalali year containing $at, in UTC. */
    public static function yearBounds(DateTimeInterface $at, string $tz): array
    {
        $jy = self::year($at, $tz);

        return [self::startOf($jy, 1, $tz)->utc(), self::startOf($jy + 1, 1, $tz)->utc()];
    }

    private static function startOf(int $jy, int $jm, string $tz): CarbonImmutable
    {
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, 1);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, $tz);
    }

    public static function year(DateTimeInterface $at, string $tz): int
    {
        $local = CarbonImmutable::instance($at)->setTimezone($tz);

        return self::fromGregorian($local->year, $local->month, $local->day)[0];
    }

    public static function date(?DateTimeInterface $at, string $tz, bool $withTime = false): string
    {
        if (! $at) {
            return '';
        }
        $local = CarbonImmutable::instance($at)->setTimezone($tz);
        [$jy, $jm, $jd] = self::fromGregorian($local->year, $local->month, $local->day);
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($withTime) {
            $out .= ' · '.$local->format('H:i');
        }

        return Digits::toPersian($out);
    }

    public static function time(?DateTimeInterface $at, string $tz): string
    {
        return $at ? Digits::toPersian(CarbonImmutable::instance($at)->setTimezone($tz)->format('H:i')) : '';
    }

    /** Parses "1405/07/13" (any digits) to a Carbon date at local midnight. */
    public static function parse(string $value, string $tz): ?CarbonImmutable
    {
        $value = Digits::toLatin(str_replace('-', '/', $value));
        if (! preg_match('#^(\d{4})\.(\d{1,2})\.(\d{1,2})$#', str_replace('/', '.', $value), $m)) {
            return null;
        }
        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > self::monthLength($jy, $jm)) {
            return null;
        }
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, $tz);
    }

    public static function monthName(int $jm): string
    {
        return ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'][$jm - 1];
    }
}
