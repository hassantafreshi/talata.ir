<?php

namespace App\Domain\Market;

use App\Models\EmergencyRate;
use App\Models\MarketQuote;
use App\Models\PlatformSetting;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\TechLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Central quote cache. One single-flight fetch every 180 s for all tenants; failed fetches
 * never refresh fetched_at; the browser only reads from here.
 */
final class QuoteService
{
    public const ASSETS = ['GOLD_18_SELL', 'GOLD_18_BUY', 'GOLD_24', 'USD_IRR', 'XAU_USD'];

    /** Stored unit per asset. Every provider value is converted to it before it can reach a price. */
    public const CANONICAL_UNIT = [
        'GOLD_18_SELL' => 'IRR_PER_GRAM', 'GOLD_18_BUY' => 'IRR_PER_GRAM', 'GOLD_24' => 'IRR_PER_GRAM',
        'USD_IRR' => 'IRR_PER_USD', 'XAU_USD' => 'USD_PER_OUNCE',
    ];

    /** 1 مثقال = 4.608 g (the bazaar unit some feeds quote in). */
    public const GRAMS_PER_MITHQAL = '4.608';

    /**
     * Converts a provider value to the asset's canonical unit. Accepts toman (×10) and per-مثقال gold quotes;
     * any other unit — or a zero/negative value — is refused, so a mislabelled feed can never price an invoice
     * 10× or 4.6× off. Returns null when refused.
     */
    public static function normalize(string $asset, string $value, string $unit): ?string
    {
        $canonical = self::CANONICAL_UNIT[$asset] ?? null;
        if (! $canonical || ! is_numeric($value)) {
            return null;
        }
        $v = BigDecimal::of($value);
        $unit = strtoupper(trim($unit));
        $v = match (true) {
            $unit === $canonical, $asset === 'USD_IRR' && $unit === 'IRR' => $v,
            $canonical === 'IRR_PER_GRAM' && $unit === 'TOMAN_PER_GRAM', $asset === 'USD_IRR' && in_array($unit, ['TOMAN_PER_USD', 'TOMAN'], true) => $v->multipliedBy(10),
            $canonical === 'IRR_PER_GRAM' && $unit === 'IRR_PER_MITHQAL' => $v->dividedBy(self::GRAMS_PER_MITHQAL, 0, RoundingMode::HalfUp),
            $canonical === 'IRR_PER_GRAM' && $unit === 'TOMAN_PER_MITHQAL' => $v->multipliedBy(10)->dividedBy(self::GRAMS_PER_MITHQAL, 0, RoundingMode::HalfUp),
            default => null,
        };

        return $v && $v->isPositive() ? (string) $v : null;
    }

    public const EMERGENCY_SOURCE_FA = 'نرخ اعلامی زرلیو (دستی)';

    public function __construct(private readonly QuoteProvider $provider) {}

    public function refresh(): bool
    {
        $lock = Cache::lock('talata.quotes.fetch', 60);
        if (! $lock->get()) {
            return false;
        }
        try {
            $data = $this->provider->fetch();
            foreach (self::ASSETS as $asset) {
                if (! isset($data[$asset])) {
                    continue;
                }
                $normalized = self::normalize($asset, (string) $data[$asset]['value'], (string) ($data[$asset]['unit'] ?? ''));
                if ($normalized === null) {
                    // Unknown unit or impossible value: keep the last good quote (it turns stale honestly).
                    TechLog::warning('quotes', 'quote refused: unknown unit or non-positive value', ['source' => $this->provider->name(), 'asset' => $asset, 'unit' => mb_substr((string) ($data[$asset]['unit'] ?? ''), 0, 40)]);

                    continue;
                }
                if (str_starts_with($asset, 'GOLD_') && ($markup = self::goldMarkupToman()) > 0) {
                    $normalized = (string) BigDecimal::of($normalized)->plus($markup * 10); // toman → rial
                }
                $previous = MarketQuote::query()->where('asset', $asset)->latest('fetched_at')->first();
                $value = BigDecimal::of($normalized);
                $change = null;
                if ($previous && ! BigDecimal::of($previous->value)->isZero()) {
                    $change = (string) $value->minus($previous->value)->multipliedBy(100)->dividedBy($previous->value, 4, RoundingMode::HalfUp);
                }
                MarketQuote::create([
                    'asset' => $asset, 'value' => (string) $value, 'unit' => self::CANONICAL_UNIT[$asset], 'change_vs_previous_pct' => $change,
                    'source' => $this->provider->name(), 'is_demo' => $this->provider->isDemo(),
                    'quote_time' => $data[$asset]['quote_time'], 'fetched_at' => now(),
                ]);
            }
            Cache::put('talata.quotes.last_error', null);
            TechLog::info('quotes', 'quotes refreshed', ['source' => $this->provider->name(), 'assets' => count($data)]);

            return true;
        } catch (Throwable $e) {
            Cache::put('talata.quotes.last_error', now()->toIso8601String(), 3600);
            TechLog::warning('quotes', 'quote fetch failed', ['source' => $this->provider->name(), 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Latest value of an asset. While staff have announced an emergency 18K sell rate (feed wrong or
     * down), it replaces the feed value everywhere merchants read the rate; issued invoices keep theirs.
     */
    public function latest(string $asset = 'GOLD_18_SELL'): ?MarketQuote
    {
        if ($asset === 'GOLD_18_SELL' && ($e = $this->emergency())) {
            $q = new MarketQuote([
                'asset' => $asset, 'value' => $e->value_irr, 'unit' => 'IRR_PER_GRAM', 'change_vs_previous_pct' => null,
                'source' => self::EMERGENCY_SOURCE_FA, 'is_demo' => false, 'quote_time' => $e->starts_at, 'fetched_at' => $e->starts_at,
            ]);
            $q->isEmergency = true;

            return $q;
        }

        return $this->feed($asset);
    }

    /** Latest value from the quote provider itself (ignores an emergency rate). */
    public function feed(string $asset): ?MarketQuote
    {
        return MarketQuote::query()->where('asset', $asset)->latest('fetched_at')->first();
    }

    public function emergency(): ?EmergencyRate
    {
        return EmergencyRate::query()->active()->latest('id')->first();
    }

    public function freshness(?MarketQuote $quote): string
    {
        if (! $quote) {
            return 'ERROR';
        }
        if ($quote->isEmergency) {
            return 'FRESH'; // valid until staff cancel it or its validity ends
        }
        if (Cache::get('talata.quotes.last_error') && $quote->fetched_at->lt(now()->subSeconds(max(240, QuoteSchedule::intervalSeconds(now()) + 60)))) {
            return 'ERROR';
        }

        $staleAfter = max((int) config('talata.quotes.stale_after_seconds', 240), QuoteSchedule::intervalSeconds(now()) + 60);

        return $quote->fetched_at->lt(now()->subSeconds($staleAfter)) ? 'STALE' : 'FRESH';
    }

    /**
     * The only place that decides whether to ask the quote API now: the gap since the last fetch must reach the
     * Tehran time-of-day interval (QuoteSchedule). The scheduler calls it every minute (talata:quotes) and, on
     * hosts without a reliable scheduler (TALATA_QUOTES_REFRESH_ON_READ=true), price reads call it too. Single
     * flight (refresh() holds a lock); after a failed fetch it waits one interval (at least 60 s).
     */
    public function refreshIfDue(bool $fromScheduler = false): bool
    {
        if (! $fromScheduler && ! config('talata.quotes.refresh_on_read')) {
            return false;
        }
        $interval = QuoteSchedule::intervalSeconds(now());
        $last = MarketQuote::query()->where('asset', 'GOLD_18_SELL')->max('fetched_at');
        $lastError = Cache::get('talata.quotes.last_error');
        // 5 s slack so a fetch that ran a moment late in the previous minute does not skip a whole minute.
        if ($last && now()->subSeconds(max(1, $interval - 5))->lt($last)) {
            return false;
        }
        if ($lastError && now()->subSeconds(max(60, $interval))->lt($lastError)) {
            return false;
        }

        return $this->refresh();
    }

    /** Fixed markup (toman per gram) added to every gold quote from the API; edited in the admin console. */
    public static function goldMarkupToman(): int
    {
        return max(0, (int) PlatformSetting::get('quotes.gold_markup_toman', config('talata.quotes.gold_markup_toman', 100000)));
    }

    /** How often a screen should ask for fresh rates: the current API interval (never faster than once a minute). */
    public static function pollSeconds(): int
    {
        return max(60, QuoteSchedule::intervalSeconds(now()));
    }

    /**
     * The «آخرین دریافت … · به‌روزرسانی بعدی …» line. Built from the real schedule so a quiet hour (every 2–3 min)
     * reads as planned, not as a stalled site. The browser keeps it ticking (resources/js/lib/quote-clock.js).
     */
    public static function clock(?MarketQuote $q): array
    {
        $interval = QuoteSchedule::intervalSeconds(now());
        $fetched = $q?->fetched_at;
        $next = $fetched ? max(0, $interval - (int) $fetched->diffInSeconds(now())) : 0;

        return [
            'interval_seconds' => $interval,
            'fetched_at' => $fetched?->toIso8601String(),
            'next_in_seconds' => $next,
            'quiet' => $interval >= 120,
            'text_fa' => self::clockText($fetched ? (int) $fetched->diffInSeconds(now()) : null, $next),
        ];
    }

    public static function clockText(?int $ago, int $next): string
    {
        if ($ago === null) {
            return 'در انتظار اولین دریافت نرخ';
        }
        $agoFa = $ago < 60 ? 'همین الان' : Digits::toPersian((string) intdiv($ago, 60)).' دقیقه پیش';
        $nextFa = $next <= 0 ? 'در حال به‌روزرسانی…' : ($next < 60 ? 'به‌روزرسانی بعدی کمتر از یک دقیقه دیگر' : 'به‌روزرسانی بعدی حدود '.Digits::toPersian((string) (int) ceil($next / 60)).' دقیقه دیگر');

        return $agoFa.' · '.$nextFa;
    }

    public function latestDto(string $tz): array
    {
        $this->refreshIfDue();
        $q = $this->latest();

        return [
            'asset' => 'GOLD_18_SELL',
            'poll_seconds' => self::pollSeconds(),
            'clock' => self::clock($this->feed('GOLD_18_SELL')),
            'value_irr' => $q ? (string) BigDecimal::of($q->value)->toScale(0, RoundingMode::HalfUp) : null,
            'value_toman_fa' => $q ? Money::toman((string) BigDecimal::of($q->value)->toScale(0, RoundingMode::HalfUp)) : null,
            'fetched_at' => $q?->fetched_at?->toIso8601String(),
            'fetched_at_fa' => $q ? Jalali::time($q->fetched_at, $tz) : null,
            'freshness' => $this->freshness($q),
            'source_fa' => $q?->source,
            'is_demo' => (bool) $q?->is_demo,
            'is_emergency' => (bool) $q?->isEmergency,
        ];
    }

    public function board(string $tz): array
    {
        $this->refreshIfDue();
        $rows = [];
        foreach (self::ASSETS as $asset) {
            $q = $this->latest($asset);
            $display = null;
            if ($q) {
                $display = $asset === 'XAU_USD'
                    ? Digits::group((string) BigDecimal::of($q->value)->toScale(0, RoundingMode::HalfUp))
                    : Money::toman((string) BigDecimal::of($q->value)->toScale(0, RoundingMode::HalfUp));
            }
            $rows[$asset] = [
                'asset' => $asset,
                'value' => $q ? (string) $q->value : null,
                'display_fa' => $display,
                'change_pct' => $q?->change_vs_previous_pct,
                'change_fa' => $q && $q->change_vs_previous_pct !== null ? Digits::percent(number_format(abs((float) $q->change_vs_previous_pct), 2, '.', '')).'٪' : null,
                'direction' => $q && $q->change_vs_previous_pct !== null ? ((float) $q->change_vs_previous_pct <=> 0) : 0,
                'freshness' => $this->freshness($q),
                'fetched_at_fa' => $q ? Jalali::time($q->fetched_at, $tz) : null,
                'is_emergency' => (bool) $q?->isEmergency,
            ];
        }
        $sell = $this->latest('GOLD_18_SELL');
        $buy = $this->latest('GOLD_18_BUY');

        return [
            'poll_seconds' => self::pollSeconds(),
            'clock' => self::clock($this->feed('GOLD_18_SELL')),
            'rows' => $rows,
            'spread_fa' => $sell && $buy ? Money::toman((string) BigDecimal::of($sell->value)->minus($buy->value)->toScale(0, RoundingMode::HalfUp)) : null,
            'fetched_at_fa' => $sell ? Jalali::time($sell->fetched_at, $tz) : null,
            'freshness' => $this->freshness($sell),
            'source_fa' => $sell?->source,
            'is_demo' => (bool) $sell?->is_demo,
            'is_emergency' => (bool) $sell?->isEmergency,
        ];
    }
}
