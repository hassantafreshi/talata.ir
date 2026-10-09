<?php

namespace App\Support;

/** Iranian national ID (کد ملی): 10 digits with the official mod-11 check digit. Optional everywhere. */
final class NationalId
{
    /** Normalised 10 digits, or null when not a valid national ID. Persian/Arabic digits, spaces and dashes accepted. */
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', Digits::toLatin((string) $raw)) ?? '';
        if (strlen($d) >= 8 && strlen($d) < 10) {
            $d = str_pad($d, 10, '0', STR_PAD_LEFT); // leading zeros are often dropped when typed
        }
        if (! preg_match('/^\d{10}$/', $d) || preg_match('/^(\d)\1{9}$/', $d)) {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $d[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $check = (int) $d[9];

        return ($r < 2 ? $check === $r : $check === 11 - $r) ? $d : null;
    }
}
