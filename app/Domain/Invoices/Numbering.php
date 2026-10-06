<?php

namespace App\Domain\Invoices;

use App\Domain\DomainError;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\TenantSetting;
use App\Support\Digits;
use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * Configurable invoice numbering (docs/INVOICE_NUMBERING.md).
 *
 * number = [prefix][sep][year][sep][month][sep]sequence   (parts that are off are skipped)
 * The sequence restarts per period (Nowruz yearly, every Jalali month, or never). A period is a counter row
 * keyed by (series, period_key); allocation runs inside issue() under the tenant row lock. Issued numbers are
 * part of the snapshot and never change when the settings change.
 */
final class Numbering
{
    public const KEY = 'invoice_numbering';

    public const SERIES = 'SALE';

    public const RESETS = ['yearly', 'monthly', 'never'];

    public const YEAR_FORMATS = ['none', 'full', 'short'];

    public const SEPARATORS = ['-', '/', ''];

    public const DEFAULT = [
        'prefix' => '', 'year' => 'full', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'yearly',
    ];

    /** One-tap presets for the settings page (label, example from the current date, settings). */
    public const PRESETS = [
        'year_seq' => ['label' => 'سال - شماره', 'hint' => 'هر سال از نوروز از ۱ شروع می‌شود (پیش‌فرض)', 'settings' => ['prefix' => '', 'year' => 'full', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'yearly']],
        'continuous' => ['label' => 'فقط شماره پیوسته', 'hint' => 'مثل دفترچه فاکتور کاغذی؛ هیچ‌وقت از اول شروع نمی‌شود', 'settings' => ['prefix' => '', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 1, 'reset' => 'never']],
        'year_month_seq' => ['label' => 'سال / ماه / شماره', 'hint' => 'برای فروشگاه پرکار؛ هر ماه از ۱ شروع می‌شود', 'settings' => ['prefix' => '', 'year' => 'full', 'month' => true, 'separator' => '/', 'digits' => 3, 'reset' => 'monthly']],
        'prefixed' => ['label' => 'حرف + سال - شماره', 'hint' => 'برای چند شعبه یا چند نوع کسب‌وکار (مثلاً ط برای طلا، ن برای نقره)', 'settings' => ['prefix' => 'ط', 'year' => 'short', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'yearly']],
    ];

    public static function settings(): array
    {
        $row = TenantSetting::query()->where('key', self::KEY)->first();

        return $row ? self::sanitize($row->value, false) : self::DEFAULT;
    }

    /**
     * Validates untrusted input. Rules keep numbers unique and readable:
     * a yearly reset needs the year in the number, a monthly reset needs year and month.
     */
    public static function sanitize(array $in, bool $strict = true): array
    {
        $errors = [];
        $raw = trim((string) ($in['prefix'] ?? ''));
        $prefix = $raw === '' ? '' : strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if ($prefix !== '' && ! preg_match('/^[\p{Arabic}A-Za-z0-9]{1,6}$/u', $prefix)) {
            $errors['prefix'] = ['پیشوند فقط حرف یا عدد و حداکثر ۶ نویسه (بدون فاصله و علامت).'];
        }
        $year = in_array($in['year'] ?? 'full', self::YEAR_FORMATS, true) ? ($in['year'] ?? 'full') : 'full';
        $month = filter_var($in['month'] ?? false, FILTER_VALIDATE_BOOL);
        $sep = in_array($in['separator'] ?? '-', self::SEPARATORS, true) ? ($in['separator'] ?? '-') : '-';
        $digits = (int) ($in['digits'] ?? 4);
        if ($digits < 1 || $digits > 8) {
            $errors['digits'] = ['تعداد رقم شماره بین ۱ تا ۸ باشد.'];
        }
        $reset = in_array($in['reset'] ?? 'yearly', self::RESETS, true) ? ($in['reset'] ?? 'yearly') : 'yearly';
        if ($reset === 'yearly' && $year === 'none') {
            $errors['year'] = ['اگر شماره هر سال از ۱ شروع شود، سال باید در شماره بیاید تا شماره‌ها تکراری نشوند.'];
        }
        if ($reset === 'monthly' && ($year === 'none' || ! $month)) {
            $errors['month'] = ['اگر شماره هر ماه از ۱ شروع شود، سال و ماه باید در شماره بیایند.'];
        }
        if ($month && $year === 'none') {
            $errors['month'] = ['ماه بدون سال معنی ندارد؛ سال را هم روشن کنید.'];
        }
        if ($errors && $strict) {
            throw new DomainError('VALIDATION', 'تنظیم شماره‌گذاری کامل نیست.', 422, ['errors' => $errors]);
        }
        if ($errors) {
            return self::DEFAULT;
        }

        return ['prefix' => $prefix, 'year' => $year, 'month' => $month, 'separator' => $sep, 'digits' => max(1, min(8, $digits)), 'reset' => $reset];
    }

    /** Counter key for the period containing $at: "1405", "1405-07" or "ALL". */
    public static function periodKey(array $s, CarbonImmutable $at, string $tz): string
    {
        $local = $at->setTimezone($tz);
        [$jy, $jm] = Jalali::fromGregorian($local->year, $local->month, $local->day);

        return match ($s['reset']) {
            'monthly' => sprintf('%04d-%02d', $jy, $jm),
            'never' => 'ALL',
            default => (string) $jy,
        };
    }

    /** The printed number (Latin digits; prefix may be Persian letters). */
    public static function format(array $s, CarbonImmutable $at, string $tz, int $seq): string
    {
        $local = $at->setTimezone($tz);
        [$jy, $jm] = Jalali::fromGregorian($local->year, $local->month, $local->day);
        $parts = [];
        if ($s['prefix'] !== '') {
            $parts[] = $s['prefix'];
        }
        if ($s['year'] !== 'none') {
            $parts[] = $s['year'] === 'short' ? sprintf('%02d', $jy % 100) : (string) $jy;
        }
        if ($s['month'] && $s['year'] !== 'none') {
            $parts[] = sprintf('%02d', $jm);
        }
        $parts[] = str_pad((string) $seq, $s['digits'], '0', STR_PAD_LEFT);

        return implode($s['separator'], $parts);
    }

    /** Next sequence number for the current period without allocating it. */
    public static function nextSeq(array $s, CarbonImmutable $at, string $tz): int
    {
        $counter = InvoiceCounter::query()->where('series', self::SERIES)->where('period_key', self::periodKey($s, $at, $tz))->first();

        return ($counter?->last_seq ?? 0) + 1;
    }

    /**
     * Allocates the next number. MUST run inside the issue transaction after the tenant row lock.
     * A number that already exists (e.g. after switching formats) is skipped, never reused.
     *
     * @return array{number:string,seq:int,period_key:string,jalali_year:int}
     */
    public static function allocate(CarbonImmutable $at, string $tz): array
    {
        $s = self::settings();
        $key = self::periodKey($s, $at, $tz);
        $counter = InvoiceCounter::query()->where('series', self::SERIES)->where('period_key', $key)->lockForUpdate()->first()
            ?? InvoiceCounter::create(['series' => self::SERIES, 'period_key' => $key, 'jalali_year' => Jalali::year($at, $tz), 'last_seq' => 0]);
        do {
            $counter->last_seq++;
            $number = self::format($s, $at, $tz, $counter->last_seq);
        } while (Invoice::query()->where('number', $number)->exists());
        $counter->save();

        return ['number' => $number, 'seq' => $counter->last_seq, 'period_key' => $key, 'jalali_year' => Jalali::year($at, $tz)];
    }

    /**
     * Owner sets the next number (e.g. to continue a paper invoice book). Lower than an already used number
     * in this period is refused, because it could repeat a printed number.
     */
    public static function setNext(array $s, int $next, CarbonImmutable $at, string $tz): void
    {
        $key = self::periodKey($s, $at, $tz);
        $counter = InvoiceCounter::query()->where('series', self::SERIES)->where('period_key', $key)->lockForUpdate()->first();
        $used = $counter?->last_seq ?? 0;
        if ($next <= $used) {
            throw new DomainError('VALIDATION', 'شماره بعدی نمی‌تواند از شماره‌های صادرشده کمتر باشد.', 422, ['errors' => ['next' => ['حداقل '.Digits::toPersian((string) ($used + 1)).' — شماره‌های قبلی تکرار نمی‌شوند.']]]);
        }
        if ($next > 99_999_999) {
            throw new DomainError('VALIDATION', 'شماره بعدی خیلی بزرگ است.', 422, ['errors' => ['next' => ['حداکثر ۸ رقم.']]]);
        }
        if ($counter) {
            $counter->update(['last_seq' => $next - 1]);
        } else {
            InvoiceCounter::create(['series' => self::SERIES, 'period_key' => $key, 'jalali_year' => Jalali::year($at, $tz), 'last_seq' => $next - 1]);
        }
    }

    /** Three upcoming numbers for the preview (no allocation). @return list<string> */
    public static function preview(array $s, CarbonImmutable $at, string $tz, ?int $next = null): array
    {
        $n = $next ?? self::nextSeq($s, $at, $tz);

        return array_map(fn ($i) => self::format($s, $at, $tz, $n + $i), [0, 1, 2]);
    }
}
