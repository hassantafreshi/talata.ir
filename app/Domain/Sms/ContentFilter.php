<?php

namespace App\Domain\Sms;

use App\Models\PlatformSetting;

/**
 * Words a shop may not put in its custom invoice-SMS text (owner request 2026-10-07): profanity, insults and
 * political or otherwise sensitive phrases. The message goes out from Zarlio's line, so the service answers for
 * it. Matching is on normalised text (Arabic letters, diacritics, half-spaces, case) and on whole words, so
 * «عکس» never matches «کس»; long terms are also caught when their letters are split by spaces or dots.
 * Staff can add words in the admin console (PlatformSetting `sms.blocked_words`). Deliberately no single
 * ordinary words that are also street or place names (انقلاب، آزادی، جمهوری، شهید).
 */
final class ContentFilter
{
    public const PROFANITY = [
        'کس', 'کص', 'کیر', 'کون', 'کونی', 'کسکش', 'کس کش', 'کسخل', 'کس خل', 'کیری', 'جنده', 'جاکش', 'مادرجنده', 'ننه جنده',
        'حرامزاده', 'حرومزاده', 'پدرسگ', 'پدر سگ', 'گاییدم', 'گاییدن', 'بگا', 'گوه', 'گه', 'لاشی', 'دیوث', 'قرمساق', 'پفیوز',
        'fuck', 'fucking', 'shit', 'bitch', 'asshole', 'bastard', 'dick', 'pussy', 'kos', 'kir', 'koon', 'jende', 'jakesh',
    ];

    public const INSULTS = [
        'احمق', 'الاغ', 'بیشعور', 'بی شعور', 'خفه شو', 'کثافت', 'عوضی', 'نفهم', 'سگ صفت', 'حیوان', 'آشغال', 'گدا', 'دزد',
        'بی ناموس', 'بیناموس', 'بی شرف', 'بیشرف', 'خاک بر سرت', 'idiot', 'stupid',
    ];

    public const POLITICAL = [
        'خامنه ای', 'خمینی', 'رهبر انقلاب', 'رهبری انقلاب', 'ولی فقیه', 'ولایت فقیه', 'جمهوری اسلامی', 'مرگ بر', 'درود بر شاه',
        'جاوید شاه', 'پهلوی', 'براندازی', 'رژیم', 'منافقین', 'مجاهدین خلق', 'اسرائیل', 'صهیونیست', 'زن زندگی آزادی',
        'تظاهرات', 'اعتصاب', 'اعتراضات', 'اعدام', 'سپاه پاسداران', 'بسیج', 'انتخابات', 'رئیس جمهور', 'رییس جمهور',
        'داعش', 'طالبان', 'تروریست', 'کودتا',
    ];

    /** Normalised text: lower case, Persian letters, no diacritics/tatweel, half-spaces and punctuation → single space. */
    public static function normalize(string $text): string
    {
        // Not Digits::toLatin(): it also drops spaces, which would glue words together.
        $t = mb_strtolower(strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $t = strtr($t, ['ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ۀ' => 'ه', 'ؤ' => 'و']);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t) ?? $t;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t);
    }

    /** @return list<string> extra words staff added in the admin console */
    public static function extraWords(): array
    {
        return array_values(array_filter(array_map('strval', (array) PlatformSetting::get('sms.blocked_words', []))));
    }

    /**
     * First blocked term in $text, or null.
     *
     * @return array{word: string, kind: 'profanity'|'insult'|'political'|'custom'}|null
     */
    public static function firstHit(string $text): ?array
    {
        $norm = self::normalize($text);
        if ($norm === '') {
            return null;
        }
        foreach (['profanity' => self::PROFANITY, 'insult' => self::INSULTS, 'political' => self::POLITICAL, 'custom' => self::extraWords()] as $kind => $words) {
            foreach ($words as $word) {
                $w = self::normalize($word);
                if ($w === '') {
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $norm)) {
                    return ['word' => $word, 'kind' => $kind];
                }
                // «ک.ث.ا.ف.ت» / «م ر گ ب ر»: letters split by spaces or dots — long terms only, still whole words.
                $wSquashed = str_replace(' ', '', $w);
                if (mb_strlen($wSquashed) >= 5 && preg_match('/(?<![\p{L}\p{N}])'.implode(' ?', array_map(fn ($c) => preg_quote($c, '/'), mb_str_split($wSquashed))).'(?![\p{L}\p{N}])/u', $norm)) {
                    return ['word' => $word, 'kind' => $kind];
                }
            }
        }

        return null;
    }
}
