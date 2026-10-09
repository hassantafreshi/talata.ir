<?php

namespace App\Domain\Pricing;

/**
 * Registry of row pricing policies by item type. Phase 1 enables GOLD, MISC and GOLD_IN;
 * v2 registers SILVER_IR_V1 / COIN_IR_V1 / MELTED_GOLD_IR_V1 here (docs/ROADMAP_V2_BUSINESS_TYPES.md §3).
 */
final class PolicyRegistry
{
    /** @var array<string,PricingPolicy> */
    private array $byType = [];

    /** @var array<string,string> item type => tax category */
    private array $taxCategory = [];

    public static function default(): self
    {
        $limits = config('talata.invoices');
        $registry = new self;
        $registry->register('GOLD', new GoldIrV1($limits), 'GOLD_SERVICES');
        $registry->register('MISC', new ManualLineV1($limits), null);
        // Gold received from the customer: credited against the sale, no tax category (no VAT on metal bought).
        $registry->register('GOLD_IN', new GoldInV1($limits), null);

        return $registry;
    }

    public function register(string $itemType, PricingPolicy $policy, ?string $taxCategory): void
    {
        $this->byType[$itemType] = $policy;
        if ($taxCategory) {
            $this->taxCategory[$itemType] = $taxCategory;
        }
    }

    public function for(string $itemType): PricingPolicy
    {
        return $this->byType[$itemType] ?? throw new PricingError('ITEM_TYPE_UNSUPPORTED', 'item_type');
    }

    public function taxCategory(string $itemType): ?string
    {
        return $this->taxCategory[$itemType] ?? null;
    }

    /** @return list<string> */
    public function itemTypes(): array
    {
        return array_keys($this->byType);
    }
}
