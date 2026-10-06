<?php

namespace App\Domain\Market;

use App\Models\EmergencyRate;
use App\Models\MarketQuote;
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
                $previous = MarketQuote::query()->where('asset', $asset)->latest('fetched_at')->first();
                $value = BigDecimal::of($data[$asset]['value']);
                $change = null;
                if ($previous && ! BigDecimal::of($previous->value)->isZero()) {
                    $change = (string) $value->minus($previous->value)->multipliedBy(100)->dividedBy($previous->value, 4, RoundingMode::HalfUp);
                }
                MarketQuote::create([
                    'asset' => $asset, 'value' => (string) $value, 'unit' => $data[$asset]['unit'], 'change_vs_previous_pct' => $change,
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
        if (Cache::get('talata.quotes.last_error') && $quote->fetched_at->lt(now()->subMinutes(4))) {
            return 'ERROR';
        }

        return $quote->fetched_at->lt(now()->subSeconds((int) config('talata.quotes.stale_after_seconds', 240))) ? 'STALE' : 'FRESH';
    }

    public function latestDto(string $tz): array
    {
        $q = $this->latest();

        return [
            'asset' => 'GOLD_18_SELL',
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
