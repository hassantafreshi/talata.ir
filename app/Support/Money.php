<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** IRR is the only stored currency; the UI shows toman (exactly 10 IRR = 1 toman). */
final class Money
{
    public static function irrToToman(string|int|null $irr): string
    {
        $irr = BigDecimal::of((string) ($irr ?? '0'));

        return (string) $irr->dividedBy(10, 1, RoundingMode::Unnecessary)->strippedOfTrailingZeros();
    }

    /** "۲۱٬۵۶۲٬۰۰۰" from IRR "215620000". */
    public static function toman(string|int|null $irr): string
    {
        $toman = BigDecimal::of(self::irrToToman($irr));
        $int = (string) $toman->toScale(0, RoundingMode::Down);
        $frac = $toman->minus(BigDecimal::of($int))->abs();
        $out = Digits::group($int);
        if (! $frac->isZero()) {
            $out .= '٫'.Digits::toPersian(substr((string) $frac, 2));
        }

        return $out;
    }

    /** Parses a toman amount typed in any digits into an IRR integer string; null if invalid. */
    public static function parseTomanToIrr(?string $raw, bool $allowZero = false): ?string
    {
        $value = Digits::toLatin($raw);
        if ($value === '' || ! preg_match('/^\d{1,16}(\.\d)?$/', $value)) {
            return null;
        }
        $irr = BigDecimal::of($value)->multipliedBy(10);
        if (! $irr->isEqualTo($irr->toScale(0, RoundingMode::Down))) {
            return null;
        }
        $irr = $irr->toScale(0);
        if ($irr->isZero() && ! $allowZero) {
            return null;
        }

        return (string) $irr;
    }

    public static function vat(string $subtotalIrr, string $ratePercent): string
    {
        return (string) BigDecimal::of($subtotalIrr)->multipliedBy($ratePercent)->dividedBy(100, 0, RoundingMode::HalfUp);
    }
}
