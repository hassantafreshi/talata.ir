<?php

namespace App\Domain\Market;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * BrsApi gold/currency web service (brsapi.ir, `Market/Gold_Currency.php?key=…`). Prices are toman; the feed
 * gives ONE 18-karat price (used as the market/sell price). It has no separate shop buy price, so GOLD_18_BUY is
 * left out rather than invented: «خرید از شما» shows «—» and gold received from a customer takes a typed rate.
 */
final class BrsApiQuoteProvider implements QuoteProvider
{
    /** Feed symbol → our asset and unit. */
    private const MAP = [
        'gold' => ['IR_GOLD_18K' => ['GOLD_18_SELL', 'TOMAN_PER_GRAM'], 'IR_GOLD_24K' => ['GOLD_24', 'TOMAN_PER_GRAM'], 'XAUUSD' => ['XAU_USD', 'USD_PER_OUNCE']],
        'currency' => ['USD' => ['USD_IRR', 'TOMAN_PER_USD']],
    ];

    public function __construct(private readonly string $key, private readonly string $url, private readonly int $timeout = 10) {}

    public function name(): string
    {
        return 'BrsApi';
    }

    public function isDemo(): bool
    {
        return false;
    }

    public function fetch(): array
    {
        if ($this->key === '') {
            throw new RuntimeException('BRSAPI_KEY is not set');
        }
        $res = Http::timeout($this->timeout)->acceptJson()->withHeaders(['User-Agent' => 'Zarlio/1.0 (+https://zarlio.ir)'])
            ->get($this->url, ['key' => $this->key]);
        if (! $res->successful()) {
            // The key is never part of the message (it is in the query string).
            throw new RuntimeException('BrsApi HTTP '.$res->status());
        }
        $data = $res->json();
        if (! is_array($data)) {
            throw new RuntimeException('BrsApi: not JSON');
        }
        $out = [];
        foreach (self::MAP as $group => $symbols) {
            foreach ((array) ($data[$group] ?? []) as $row) {
                $hit = $symbols[$row['symbol'] ?? ''] ?? null;
                if (! $hit || ! isset($row['price']) || ! is_numeric($row['price'])) {
                    continue;
                }
                [$asset, $unit] = $hit;
                $out[$asset] = [
                    'value' => (string) $row['price'],
                    'unit' => $unit,
                    'quote_time' => isset($row['time_unix']) && is_numeric($row['time_unix']) ? now()->setTimestamp((int) $row['time_unix']) : null,
                ];
            }
        }
        if (! isset($out['GOLD_18_SELL'])) {
            throw new RuntimeException('BrsApi: no 18K gold price in the response');
        }

        return $out;
    }
}
