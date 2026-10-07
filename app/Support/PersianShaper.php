<?php

namespace App\Support;

/**
 * Minimal Persian/Arabic shaping for renderers that know neither joining nor bidi (PHP GD's imagettftext):
 * letters become their contextual presentation forms (isolated/final/initial/medial, لا ligatures) and the line
 * is returned in visual left-to-right order, keeping numbers and Latin runs readable. Enough for shop names,
 * phone numbers and short labels on generated images — not a general text layout engine.
 */
final class PersianShaper
{
    /** letter => [isolated, final, initial, medial]; null initial/medial = joins only to the previous letter. */
    private const FORMS = [
        'ء' => ["\u{FE80}", null, null, null],
        'آ' => ["\u{FE81}", "\u{FE82}", null, null], 'أ' => ["\u{FE83}", "\u{FE84}", null, null], 'ؤ' => ["\u{FE85}", "\u{FE86}", null, null],
        'إ' => ["\u{FE87}", "\u{FE88}", null, null], 'ئ' => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"], 'ا' => ["\u{FE8D}", "\u{FE8E}", null, null],
        'ب' => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"], 'ة' => ["\u{FE93}", "\u{FE94}", null, null], 'ت' => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"],
        'ث' => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"], 'ج' => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"], 'ح' => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
        'خ' => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"], 'د' => ["\u{FEA9}", "\u{FEAA}", null, null], 'ذ' => ["\u{FEAB}", "\u{FEAC}", null, null],
        'ر' => ["\u{FEAD}", "\u{FEAE}", null, null], 'ز' => ["\u{FEAF}", "\u{FEB0}", null, null], 'س' => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
        'ش' => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"], 'ص' => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"], 'ض' => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"],
        'ط' => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"], 'ظ' => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"], 'ع' => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
        'غ' => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"], 'ف' => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"], 'ق' => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"],
        'ك' => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"], 'ل' => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"], 'م' => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
        'ن' => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"], 'ه' => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"], 'و' => ["\u{FEED}", "\u{FEEE}", null, null],
        'ى' => ["\u{FEEF}", "\u{FEF0}", null, null], 'ي' => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"],
        'پ' => ["\u{FB56}", "\u{FB57}", "\u{FB58}", "\u{FB59}"], 'چ' => ["\u{FB7A}", "\u{FB7B}", "\u{FB7C}", "\u{FB7D}"], 'ژ' => ["\u{FB8A}", "\u{FB8B}", null, null],
        'ک' => ["\u{FB8E}", "\u{FB8F}", "\u{FB90}", "\u{FB91}"], 'گ' => ["\u{FB92}", "\u{FB93}", "\u{FB94}", "\u{FB95}"], 'ی' => ["\u{FBFC}", "\u{FBFD}", "\u{FBFE}", "\u{FBFF}"],
        'ـ' => ['ـ', 'ـ', 'ـ', 'ـ'],
    ];

    /** لا ligatures: alef variant => [isolated, final]. */
    private const LAM_ALEF = ['ا' => ["\u{FEFB}", "\u{FEFC}"], 'آ' => ["\u{FEF5}", "\u{FEF6}"], 'أ' => ["\u{FEF7}", "\u{FEF8}"], 'إ' => ["\u{FEF9}", "\u{FEFA}"]];

    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '«' => '»', '»' => '«', '<' => '>', '>' => '<'];

    public static function shape(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text; // diacritics are dropped
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return $text; // nothing to shape; Latin-only lines stay as typed
        }
        $chars = mb_str_split($text);
        $n = count($chars);
        $joinsNext = fn (?string $c) => $c !== null && isset(self::FORMS[$c]) && self::FORMS[$c][2] !== null;
        $isLetter = fn (?string $c) => $c !== null && isset(self::FORMS[$c]);

        $shaped = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === "\u{200C}") {
                continue; // half-space: breaks joining, leaves no glyph
            }
            if (! $isLetter($c)) {
                $shaped[] = $c;

                continue;
            }
            $prev = $i > 0 ? $chars[$i - 1] : null;
            $next = $i + 1 < $n ? $chars[$i + 1] : null;
            $fromPrev = $joinsNext($prev);
            if ($c === 'ل' && $next !== null && isset(self::LAM_ALEF[$next])) {
                $shaped[] = self::LAM_ALEF[$next][$fromPrev ? 1 : 0];
                $i++;

                continue;
            }
            [$iso, $fin, $ini, $med] = self::FORMS[$c];
            $toNext = $ini !== null && $isLetter($next);
            $shaped[] = match (true) {
                $fromPrev && $toNext => $med,
                $fromPrev && $fin !== null => $fin,
                $toNext => $ini,
                default => $iso,
            };
        }

        return self::visual($shaped);
    }

    /** Right-to-left base direction: reverse the line, but keep numbers and Latin words in reading order. */
    private static function visual(array $chars): string
    {
        $runs = [];
        $ltr = '';
        $isLtr = fn (?string $c) => $c !== null && (bool) preg_match('/[0-9A-Za-z\x{06F0}-\x{06F9}\x{0660}-\x{0669}@_]/u', $c);
        foreach ($chars as $k => $c) {
            // Inside a number or Latin run, separators followed by more of it stay in the run («۰۹۱۲ ۳۴۵ ۶۷۸۹»).
            if ($isLtr($c) || ($ltr !== '' && preg_match('/[.\-\/:+ ]/', $c) && $isLtr($chars[$k + 1] ?? null))) {
                $ltr .= $c;

                continue;
            }
            if ($ltr !== '') {
                $runs[] = [true, $ltr];
                $ltr = '';
            }
            $runs[] = [false, self::MIRROR[$c] ?? $c];
        }
        if ($ltr !== '') {
            $runs[] = [true, $ltr];
        }

        return implode('', array_map(fn ($r) => $r[1], array_reverse($runs)));
    }
}
