<?php

namespace App\Domain\Reports;

use App\Domain\DomainError;
use App\Domain\Plans\Entitlements;
use App\Models\Tenant;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales dashboard (docs/GOLD_RECEIVED_AND_DASHBOARD.md §8–§12). Reads only issued invoices' report
 * columns (written once at issuance from the snapshot), aggregated in the shop's timezone and Jalali calendar.
 * Plan gating by capability: dashboard.view (every plan) gives sales, wage and gold received for
 * day/week/month; reports.financial adds profit, VAT, 3 months, year and a custom range.
 */
final class DashboardService
{
    public const RANGES = ['day', 'week', 'month', 'quarter', 'year', 'custom'];

    public const BASIC_RANGES = ['day', 'week', 'month'];

    public const METRICS = ['sales', 'wage', 'profit', 'gold_in', 'vat'];

    public const BASIC_METRICS = ['sales', 'wage', 'gold_in'];

    /** Metrics that also have a gram value (750-equivalent grams). VAT is toman only. */
    public const GRAM_METRICS = ['sales', 'wage', 'profit', 'gold_in'];

    public const MAX_CUSTOM_DAYS = 731;

    private const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return array{full:bool,ranges:list<string>,metrics:list<string>} */
    public function access(Tenant $tenant): array
    {
        $full = $this->entitlements->can($tenant, 'reports.financial');

        return [
            'full' => $full,
            'ranges' => $full ? self::RANGES : self::BASIC_RANGES,
            'metrics' => $full ? self::METRICS : self::BASIC_METRICS,
        ];
    }

    public function report(Tenant $tenant, string $range, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): array
    {
        if (! $this->entitlements->can($tenant, 'dashboard.view')) {
            throw new DomainError('FEATURE_LOCKED', 'داشبورد در این پلن فعال نیست.', 403);
        }
        $access = $this->access($tenant);
        if (! in_array($range, self::RANGES, true)) {
            throw new DomainError('RANGE_INVALID', 'بازه زمانی درست نیست.', 422);
        }
        if (! in_array($range, $access['ranges'], true)) {
            throw new DomainError('FEATURE_LOCKED', 'این بازه در پلن پایه و حرفه‌ای فعال است.', 403, ['upgrade' => route('settings.plan')]);
        }
        $tz = $tenant->timezone;
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        [$start, $end, $buckets, $label, $prev] = $this->window($range, $from, $to, $now, $tz);

        $byKey = $this->aggregate($tenant->id, $start, $end, $tz, $buckets['unit']);
        $series = [];
        $totals = array_fill_keys(['sales_irr', 'sales_g', 'wage_irr', 'wage_g', 'profit_irr', 'profit_g', 'gold_in_irr', 'gold_in_g', 'vat_irr', 'count'], BigDecimal::zero());
        foreach ($buckets['list'] as $b) {
            $sum = array_fill_keys(array_keys($totals), BigDecimal::zero());
            foreach ($b['keys'] as $k) {
                foreach ($byKey[$k] ?? [] as $field => $value) {
                    $sum[$field] = $sum[$field]->plus($value);
                }
            }
            foreach ($sum as $field => $value) {
                $totals[$field] = $totals[$field]->plus($value);
            }
            $series[] = ['label' => $b['label'], 'title' => $b['title'], 'sum' => $sum];
        }

        $previous = $access['full'] && $prev ? $this->totals($tenant->id, $prev[0], $prev[1]) : null;
        $metrics = [];
        foreach ($access['metrics'] as $m) {
            $irr = $totals["{$m}_irr"];
            $metric = [
                // Summaries are shown in whole toman (exact IRR stays in 'irr').
                'irr' => (string) $irr, 'toman_fa' => Money::toman((string) $irr->dividedBy(10, 0, RoundingMode::HalfUp)->multipliedBy(10)),
                'series_toman' => array_map(fn ($s) => (float) (string) $s['sum']["{$m}_irr"]->dividedBy(10, 1, RoundingMode::HalfUp), $series),
            ];
            if (in_array($m, self::GRAM_METRICS, true)) {
                $g = $totals["{$m}_g"]->toScale(3, RoundingMode::HalfUp);
                $metric += [
                    'g' => (string) $g, 'g_fa' => self::grams((string) $g),
                    'series_g' => array_map(fn ($s) => (float) (string) $s['sum']["{$m}_g"]->toScale(3, RoundingMode::HalfUp), $series),
                ];
            }
            if ($previous) {
                $metric['delta_pct'] = self::delta($irr, $previous["{$m}_irr"]);
            }
            $metrics[$m] = $metric;
        }

        return [
            'range' => $range,
            'label_fa' => $label,
            'from_fa' => Jalali::date($start, $tz),
            'to_fa' => Jalali::date($end->subSecond(), $tz),
            'access' => $access,
            'locked_metrics' => array_values(array_diff(self::METRICS, $access['metrics'])),
            'invoices' => (int) (string) $totals['count'], 'invoices_fa' => Digits::toPersian((string) $totals['count']),
            'labels' => array_column($series, 'label'),
            'titles' => array_column($series, 'title'),
            'metrics' => $metrics,
            'compare_fa' => $previous ? 'نسبت به دوره قبل' : null,
            'empty' => $totals['count']->isZero(),
        ];
    }

    /**
     * Sums per local day (or hour for "day"). Voided invoices are excluded; drafts have no issued_at.
     *
     * @return array<string,array<string,BigDecimal>>
     */
    private function aggregate(int $tenantId, CarbonImmutable $start, CarbonImmutable $end, string $tz, string $unit): array
    {
        $local = "(issued_at AT TIME ZONE 'UTC' AT TIME ZONE ?)";
        $key = $unit === 'hour' ? "to_char({$local}, 'YYYY-MM-DD HH24')" : "to_char({$local}, 'YYYY-MM-DD')";
        $rows = DB::table('invoices')
            ->selectRaw("{$key} AS k, ".self::SUMS, [$tz])
            ->where('tenant_id', $tenantId)->where('status', 'issued')
            ->where('issued_at', '>=', $start->utc())->where('issued_at', '<', $end->utc())
            ->groupBy('k')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->k] = self::decimals($r);
        }

        return $out;
    }

    /** @return array<string,BigDecimal> */
    private function totals(int $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $r = DB::table('invoices')->selectRaw(self::SUMS)
            ->where('tenant_id', $tenantId)->where('status', 'issued')
            ->where('issued_at', '>=', $start->utc())->where('issued_at', '<', $end->utc())->first();

        return self::decimals($r);
    }

    /** Wage and profit in grams = amount ÷ the invoice's accepted 18K rate per gram (invoices without a rate add 0 g). */
    private const SUMS = 'COUNT(*) AS count, COALESCE(SUM(sales_total_irr),0) AS sales_irr, COALESCE(SUM(gold_out_weight_750),0) AS sales_g, '
        .'COALESCE(SUM(wage_irr),0) AS wage_irr, COALESCE(SUM(CASE WHEN accepted_rate_irr > 0 THEN wage_irr / accepted_rate_irr ELSE 0 END),0) AS wage_g, '
        .'COALESCE(SUM(profit_irr),0) AS profit_irr, COALESCE(SUM(CASE WHEN accepted_rate_irr > 0 THEN profit_irr / accepted_rate_irr ELSE 0 END),0) AS profit_g, '
        .'COALESCE(SUM(gold_in_total_irr),0) AS gold_in_irr, COALESCE(SUM(gold_in_weight_750),0) AS gold_in_g, COALESCE(SUM(vat_irr),0) AS vat_irr';

    /** @return array<string,BigDecimal> */
    private static function decimals(object $r): array
    {
        $out = [];
        foreach (['count', 'sales_irr', 'sales_g', 'wage_irr', 'wage_g', 'profit_irr', 'profit_g', 'gold_in_irr', 'gold_in_g', 'vat_irr'] as $f) {
            $out[$f] = BigDecimal::of((string) ($r->{$f} ?? '0'));
        }

        return $out;
    }

    /**
     * Window, buckets, label and previous window for a range, in local time.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable,2:array,3:string,4:?array}
     */
    private function window(string $range, ?string $from, ?string $to, CarbonImmutable $now, string $tz): array
    {
        $today = $now->startOfDay();
        [$jy, $jm] = Jalali::fromGregorian($today->year, $today->month, $today->day);
        switch ($range) {
            case 'day':
                return [$today, $today->addDay(), $this->hours($today), 'امروز '.Jalali::date($today, $tz), [$today->subDay(), $today]];
            case 'week':
                $start = $today->subDays(($today->dayOfWeek + 1) % 7); // Jalali week starts on Saturday

                return [$start, $start->addDays(7), $this->days($start, $start->addDays(7), true), 'این هفته', [$start->subDays(7), $start]];
            case 'month':
                $start = self::monthStart($jy, $jm, $tz);
                [$ny, $nm] = self::addMonths($jy, $jm, 1);
                [$py, $pm] = self::addMonths($jy, $jm, -1);
                $end = self::monthStart($ny, $nm, $tz);

                return [$start, $end, $this->days($start, $end), Jalali::monthName($jm).' '.Digits::toPersian((string) $jy), [self::monthStart($py, $pm, $tz), $start]];
            case 'quarter':
                [$sy, $sm] = self::addMonths($jy, $jm, -2);
                [$ny, $nm] = self::addMonths($jy, $jm, 1);
                [$py, $pm] = self::addMonths($sy, $sm, -3);
                $start = self::monthStart($sy, $sm, $tz);
                $end = self::monthStart($ny, $nm, $tz);

                return [$start, $end, $this->weeks($start, $end), 'سه ماه اخیر ('.Jalali::monthName($sm).' تا '.Jalali::monthName($jm).')', [self::monthStart($py, $pm, $tz), $start]];
            case 'year':
                $start = self::monthStart($jy, 1, $tz);
                $end = self::monthStart($jy + 1, 1, $tz);

                return [$start, $end, $this->months($start, $end), 'سال '.Digits::toPersian((string) $jy), [self::monthStart($jy - 1, 1, $tz), $start]];
            default:
                $s = $from ? Jalali::parse($from, $tz) : null;
                $e = $to ? Jalali::parse($to, $tz) : null;
                if (! $s || ! $e) {
                    throw new DomainError('RANGE_INVALID', 'تاریخ شروع و پایان را انتخاب کنید.', 422, ['errors' => ['from' => ['تاریخ شروع و پایان را انتخاب کنید.']]]);
                }
                if ($e->lt($s)) {
                    [$s, $e] = [$e, $s];
                }
                $e = $e->addDay();
                $days = (int) $s->diffInDays($e);
                if ($days > self::MAX_CUSTOM_DAYS) {
                    throw new DomainError('RANGE_TOO_LONG', 'بازه دلخواه حداکثر دو سال است.', 422, ['errors' => ['to' => ['بازه دلخواه حداکثر دو سال است.']]]);
                }
                $buckets = match (true) {
                    $days <= 1 => $this->hours($s),
                    $days <= 31 => $this->days($s, $e),
                    $days <= 120 => $this->weeks($s, $e),
                    default => $this->months($s, $e),
                };

                return [$s, $e, $buckets, 'از '.Jalali::date($s, $tz).' تا '.Jalali::date($e->subDay(), $tz), [$s->subDays($days), $s]];
        }
    }

    private function hours(CarbonImmutable $day): array
    {
        $list = [];
        for ($h = 0; $h < 24; $h++) {
            $list[] = ['keys' => [$day->format('Y-m-d').' '.sprintf('%02d', $h)], 'label' => Digits::toPersian((string) $h), 'title' => 'ساعت '.Digits::toPersian(sprintf('%02d', $h)).':۰۰'];
        }

        return ['unit' => 'hour', 'list' => $list];
    }

    private function days(CarbonImmutable $start, CarbonImmutable $end, bool $weekdays = false): array
    {
        $list = [];
        for ($d = $start; $d->lt($end); $d = $d->addDay()) {
            [, , $jd] = Jalali::fromGregorian($d->year, $d->month, $d->day);
            $list[] = [
                'keys' => [$d->format('Y-m-d')],
                'label' => $weekdays ? self::WEEKDAYS[($d->dayOfWeek + 1) % 7] : Digits::toPersian((string) $jd),
                'title' => ($weekdays ? self::WEEKDAYS[($d->dayOfWeek + 1) % 7].' ' : '').Jalali::date($d, $d->getTimezone()->getName()),
            ];
        }

        return ['unit' => 'day', 'list' => $list];
    }

    private function weeks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $list = [];
        for ($w = $start; $w->lt($end); $w = $w->addDays(7)) {
            $keys = [];
            $last = $w;
            for ($d = $w; $d->lt($end) && $d->lt($w->addDays(7)); $d = $d->addDay()) {
                $keys[] = $d->format('Y-m-d');
                $last = $d;
            }
            [, $m1, $d1] = Jalali::fromGregorian($w->year, $w->month, $w->day);
            $list[] = ['keys' => $keys, 'label' => Digits::toPersian("{$m1}/{$d1}"), 'title' => 'هفته '.Jalali::date($w, $w->getTimezone()->getName()).' تا '.Jalali::date($last, $last->getTimezone()->getName())];
        }

        return ['unit' => 'day', 'list' => $list];
    }

    private function months(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tz = $start->getTimezone()->getName();
        [$jy, $jm] = Jalali::fromGregorian($start->year, $start->month, $start->day);
        $list = [];
        $cursor = $start;
        while ($cursor->lt($end)) {
            [$ny, $nm] = self::addMonths($jy, $jm, 1);
            $next = self::monthStart($ny, $nm, $tz);
            $keys = [];
            for ($d = $cursor; $d->lt($next) && $d->lt($end); $d = $d->addDay()) {
                $keys[] = $d->format('Y-m-d');
            }
            $list[] = ['keys' => $keys, 'label' => Jalali::monthName($jm), 'title' => Jalali::monthName($jm).' '.Digits::toPersian((string) $jy)];
            [$jy, $jm, $cursor] = [$ny, $nm, $next];
        }

        return ['unit' => 'day', 'list' => $list];
    }

    private static function monthStart(int $jy, int $jm, string $tz): CarbonImmutable
    {
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, 1);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, $tz);
    }

    /** @return array{0:int,1:int} */
    private static function addMonths(int $jy, int $jm, int $n): array
    {
        $i = $jy * 12 + ($jm - 1) + $n;

        return [intdiv($i, 12), $i % 12 + 1];
    }

    private static function delta(BigDecimal $now, BigDecimal $before): ?string
    {
        if ($before->isZero()) {
            return null;
        }

        return (string) $now->minus($before)->multipliedBy(100)->dividedBy($before, 0, RoundingMode::HalfUp);
    }

    public static function grams(string $g): string
    {
        return Digits::toPersian((string) BigDecimal::of($g)->strippedOfTrailingZeros()).' گرم';
    }
}
