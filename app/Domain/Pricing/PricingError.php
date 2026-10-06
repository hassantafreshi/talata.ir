<?php

namespace App\Domain\Pricing;

use RuntimeException;

final class PricingError extends RuntimeException
{
    /** @param array<string,string> $context */
    public function __construct(public readonly string $codeName, public readonly string $field = '', public readonly array $context = [])
    {
        parent::__construct($codeName);
    }
}
