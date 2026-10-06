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
        // A placeholder may not be glued to letters, digits or URL characters: «{shop_name}.ir/pay» would turn a
        // shop name into a web address in a message sent from our line.
        if (preg_match('~[\p{L}\p{N}._\-/@\\\\:]\{(shop_name|invoice_number|amount|invoice_link)\}|\{(shop_name|invoice_number|amount)\}[\p{L}\p{N}._\-/@\\\\]|\{invoice_link\}[\p{L}\p{N}/@\\\\]~u', $template)) {
            throw new DomainError('TEMPLATE_PLACEHOLDER_GLUED', 'هر عبارت داخل {} باید با فاصله یا علامت از بقیه متن جدا باشد.');
        }
        if (substr_count($template, '{invoice_number}') < 1) {
            throw new DomainError('TEMPLATE_NUMBER_REQUIRED', 'متن باید شماره فاکتور {invoice_number} را داشته باشد تا پیامک فقط اطلاع‌رسانی همان فاکتور باشد.');
        }
        $plain = str_replace(self::PLACEHOLDERS, '', $template);
        if (self::containsLinkOrPhone($plain)) {
            throw new DomainError('TEMPLATE_FORBIDDEN_CONTENT', 'در متن پیامک لینک یا شماره تلفن دیگری مجاز نیست.');
        }
        if (self::looksLikeImpersonation($plain)) {
            throw new DomainError('TEMPLATE_IMPERSONATION', 'متن پیامک نباید شبیه پیام بانک، سامانه دولتی، جایزه یا کد تأیید باشد.');
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
    /** Distinctive words: matched anywhere, so glued spellings («بانکملت») are caught too. */
    public const PHISHING_WORDS = [
        'بانک', 'شاپرک', 'سامانه', 'دولت', 'یارانه', 'عدالت', 'سهام', 'پلیس', 'ابلاغ', 'دادگستری',
        'مالیات', 'مسدود', 'اخطار', 'هشدار', 'برنده', 'جایزه', 'قرعه', 'کد تایید', 'کد تأیید', 'پشتیبانی',
        'همراه اول', 'ایرانسل', 'رایتل', 'زرلیو',
    ];

    /**
     * Short words that also occur inside ordinary shop names («آفتاب» contains فتا, «قرمز» رمز, «دوام» وام,
     * «استثنایی» ثنا): matched only as whole words.
     */
    public const PHISHING_WHOLE_WORDS = ['فتا', 'ثنا', 'رمز', 'وام', 'قوه'];

    public static function looksLikeImpersonation(string $text): bool
    {
        $t = str_replace(["\u{200C}", 'ي', 'ك'], [' ', 'ی', 'ک'], mb_strtolower($text));
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        foreach (self::PHISHING_WORDS as $w) {
            if (mb_strpos($t, $w) !== false) {
                return true;
            }
        }
        foreach (self::PHISHING_WHOLE_WORDS as $w) {
            if (preg_match('/(?<!\p{Arabic})'.preg_quote($w, '/').'(?!\p{Arabic})/u', $t)) {
                return true;
            }
        }

        // Latin brand words match inside other words too («mellatbank»); short ambiguous ones only as words.
        if (preg_match('/(bank|shaparak|zarlio|talata|police|adliran|yaraneh|edalat|sahamedalat)/iu', $t)) {
            return true;
        }

        return (bool) preg_match('/\b(sana|support|verify|code|otp)\b/iu', $t);
    }

    /**
     * Iranian phone-number shapes once separators are removed (mobile 09…/9…/98…, or a landline with area
     * code). Used for invoice numbers inside SMS: a number styled «۰۹۱۲-۳۴۵۶۷۸۹» must never go out.
     */
    public static function looksLikePhoneNumber(string $text): bool
    {
        $latin = Digits::toLatin($text);
        $compact = preg_replace('/(?<=\d)[\s\x{200C}\x{200E}\x{200F}\-\x{2013}\x{2014}_.()\/\\|*+]+(?=\d)/u', '', $latin) ?? $latin;

        return (bool) preg_match('/(?<!\d)(0\d{10}|98\d{10}|9\d{9})(?!\d)/', $compact);
    }

    /**
     * Final check of what will actually be sent, after the merchant's template and shop name are put together
     * (each can pass alone and still combine into a link or a bank-like text). Numbers and the trusted link are
     * neutralised; the invoice number is checked separately for phone-number shapes.
     */
    public static function assertSafeToSend(string $template, string $shopName, string $invoiceNumber): void
    {
        $probe = self::render($template, ['shop_name' => $shopName, 'invoice_number' => 'N', 'amount' => 'A', 'invoice_link' => ' ']);
        if (self::containsLinkOrPhone($probe) || self::looksLikeImpersonation($probe) || self::looksLikePhoneNumber($invoiceNumber)) {
            throw new DomainError('SMS_CONTENT_BLOCKED', 'متن این پیامک مجاز نیست: شبیه لینک، شماره تلفن یا پیام بانک و سامانه می‌شود. نام فروشگاه، متن پیامک یا شیوه شماره‌گذاری را اصلاح کنید.', 422);
        }
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
