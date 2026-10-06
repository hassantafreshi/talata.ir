<?php

namespace App\Domain\Sms;

use App\Domain\DomainError;
use App\Support\Digits;

/**
 * Invoice SMS text. Merchants never send free text: only an allowlisted template with
 * placeholders; the invoice link is mandatory; no other URLs or phone numbers are allowed.
 */
final class SmsTemplate
{
    public const DEFAULT = '{shop_name}: فاکتور {invoice_number} شما صادر شد. مشاهده: {invoice_link}';

    public const PLACEHOLDERS = ['{shop_name}', '{invoice_number}', '{amount}', '{invoice_link}'];

    public static function validate(string $template): string
    {
        $template = trim(preg_replace('/\s+/u', ' ', strip_tags($template)) ?? '');
        if (mb_strlen($template) > config('talata.sms.template_max_chars')) {
            throw new DomainError('TEMPLATE_TOO_LONG', 'متن پیامک بیش از حد طولانی است.');
        }
        if (substr_count($template, '{invoice_link}') !== 1) {
            throw new DomainError('TEMPLATE_LINK_REQUIRED', 'متن باید دقیقاً یک بار {invoice_link} داشته باشد.');
        }
        preg_match_all('/\{[^}]*\}/u', $template, $m);
        foreach ($m[0] as $placeholder) {
            if (! in_array($placeholder, self::PLACEHOLDERS, true)) {
                throw new DomainError('TEMPLATE_PLACEHOLDER', "عبارت {$placeholder} مجاز نیست.");
            }
        }
        $plain = str_replace(self::PLACEHOLDERS, '', $template);
        if (self::containsLinkOrPhone($plain)) {
            throw new DomainError('TEMPLATE_FORBIDDEN_CONTENT', 'در متن پیامک لینک یا شماره تلفن دیگری مجاز نیست.');
        }

        return $template;
    }

    public static function containsLinkOrPhone(string $text): bool
    {
        $latin = mb_strtolower(Digits::toLatin($text));
        // Undo common obfuscation: separators between digits ("0912 345-6789") and spaced dots ("site . com").
        $compact = preg_replace('/(?<=\d)[\s\x{200C}\-\x{2013}\x{2014}_.()\/\\|*+]+(?=\d)/u', '', $latin) ?? $latin;
        $compact = preg_replace('/\s*(\.|\x{066B}|\x{06D4}|\bdot\b|نقطه)\s*/u', '.', $compact) ?? $compact;

        return (bool) preg_match('~(https?:|www\.|[a-z0-9-]{2,}\.[a-z]{2,}\b|t\.me|wa\.me|@[a-z0-9_]{3,}|\d{6,}|\+\d{4,})~iu', $compact);
    }

    /**
     * Shop names travel inside SMS under our sender line. Block wording that impersonates banks,
     * government or prize/verification messages (common Iranian SMS phishing patterns).
     */
    public const PHISHING_WORDS = [
        'بانک', 'شاپرک', 'ثنا', 'سامانه', 'دولت', 'یارانه', 'عدالت', 'سهام', 'پلیس', 'فتا', 'ابلاغ', 'دادگستری', 'قوه',
        'مالیات', 'مسدود', 'اخطار', 'هشدار', 'برنده', 'جایزه', 'قرعه', 'رمز', 'کد تایید', 'کد تأیید', 'پشتیبانی', 'وام',
        'همراه اول', 'ایرانسل', 'رایتل', 'طلاتا',
    ];

    public static function looksLikeImpersonation(string $text): bool
    {
        $t = str_replace(["\u{200C}", 'ي', 'ك'], [' ', 'ی', 'ک'], mb_strtolower($text));
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        foreach (self::PHISHING_WORDS as $w) {
            if (mb_strpos($t, $w) !== false) {
                return true;
            }
        }

        return (bool) preg_match('/\b(bank|shaparak|sana|police|support|talata|verify|code)\b/iu', $t);
    }

    public static function render(string $template, array $vars): string
    {
        return strtr($template, [
            '{shop_name}' => $vars['shop_name'],
            '{invoice_number}' => $vars['invoice_number'],
            '{amount}' => $vars['amount'],
            '{invoice_link}' => $vars['invoice_link'],
        ]);
    }
}
