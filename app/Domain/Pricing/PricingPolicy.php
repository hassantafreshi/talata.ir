<?php

namespace App\Domain\Pricing;

/** A versioned row pricing contract. New business types (v2) add policies without schema changes. */
interface PricingPolicy
{
    public function id(): string;

    /** @return array<string,mixed> */
    public function price(array $input): array;
}
