<?php

namespace App\Domain\Sms;

/** Segment estimator. Persian text is Unicode (UCS-2): 70 chars single, 67 per part. */
final class Segments
{
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    public static function count(string $body): int
    {
        $len = mb_strlen($body, 'UTF-8');
        $gsm = true;
        foreach (mb_str_split($body) as $ch) {
            if (mb_strpos(self::GSM, $ch) === false) {
                $gsm = false;
                break;
            }
        }
        $cfg = config('talata.sms');
        [$single, $multi] = $gsm ? [$cfg['gsm_single'], $cfg['gsm_multi']] : [$cfg['unicode_single'], $cfg['unicode_multi']];

        return $len <= $single ? 1 : (int) ceil($len / $multi);
    }
}
