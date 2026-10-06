<?php

namespace App\Domain\Market;

/**
 * Clearly labelled sample prices (no live market connection). Values drift slightly every
 * 3-minute slot so freshness and change indicators can be exercised end to end.
 */
final class DemoQuoteProvider implements QuoteProvider
{
    public function name(): string
    {
        return 'بازار (نمونه)';
    }

    public function isDemo(): bool
    {
        return true;
    }

    public function fetch(): array
    {
        $slot = intdiv(time(), 180);
        $drift = (($slot * 7919) % 41) - 20; // -20..20 thousand toman
        $sell = 100_000_000 + $drift * 10_000;   // IRR per gram 18K
        $buy = $sell - 1_000_000;
        $k24 = intdiv($sell * 1000 * 1000, 750 * 1000);

        return [
            'GOLD_18_SELL' => ['value' => (string) $sell, 'unit' => 'IRR_PER_GRAM', 'quote_time' => now()],
            'GOLD_18_BUY' => ['value' => (string) $buy, 'unit' => 'IRR_PER_GRAM', 'quote_time' => now()],
            'GOLD_24' => ['value' => (string) $k24, 'unit' => 'IRR_PER_GRAM', 'quote_time' => now()],
            'USD_IRR' => ['value' => '1000000', 'unit' => 'IRR', 'quote_time' => now()],
            'XAU_USD' => ['value' => (string) (2650 + ($drift / 10)), 'unit' => 'USD_PER_OUNCE', 'quote_time' => now()],
        ];
    }
}
