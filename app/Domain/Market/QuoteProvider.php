<?php

namespace App\Domain\Market;

/** Adapter for a market data provider. Returns normalized values; never called from the browser. */
interface QuoteProvider
{
    public function name(): string;

    public function isDemo(): bool;

    /**
     * Units: gold IRR_PER_GRAM (or TOMAN_PER_GRAM / IRR_PER_MITHQAL / TOMAN_PER_MITHQAL), USD_IRR IRR_PER_USD
     * (or TOMAN_PER_USD), XAU_USD USD_PER_OUNCE. QuoteService::normalize() converts; any other unit is refused.
     *
     * @return array<string,array{value:string,unit:string,quote_time:?\DateTimeInterface}>
     *                                                                                      keys: GOLD_18_BUY, GOLD_18_SELL, GOLD_24, USD_IRR (IRR values), XAU_USD (USD per ounce)
     */
    public function fetch(): array;
}
