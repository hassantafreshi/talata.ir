<?php

namespace App\Domain\Market;

/** Adapter for a market data provider. Returns normalized values; never called from the browser. */
interface QuoteProvider
{
    public function name(): string;

    public function isDemo(): bool;

    /**
     * @return array<string,array{value:string,unit:string,quote_time:?\DateTimeInterface}>
     *                                                                                      keys: GOLD_18_BUY, GOLD_18_SELL, GOLD_24, USD_IRR (IRR values), XAU_USD (USD per ounce)
     */
    public function fetch(): array;
}
