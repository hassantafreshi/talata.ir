<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;

/** MANUAL_LINE_V1: the entered value is the final price of the whole MISC row; no gold math or tax added. */
final class ManualLineV1 implements PricingPolicy
{
    public const ID = 'MANUAL_LINE_V1';

    public function __construct(private readonly array $limits = []) {}

    public function id(): string
    {
        return self::ID;
    }

    public function price(array $input): array
    {
        $raw = (string) ($input['manual_total_irr'] ?? '');
        if ($raw === '' || ! preg_match('/^\d{1,20}$/', $raw)) {
            throw new PricingError('REQUIRED', 'manual_total_irr');
        }
        $value = BigDecimal::of($raw);
        if ($value->isZero()) {
            throw new PricingError('MUST_BE_POSITIVE', 'manual_total_irr');
        }
        if ($value->isGreaterThan($this->limits['max_amount_irr'] ?? '1000000000000000')) {
            throw new PricingError('OUT_OF_RANGE', 'manual_total_irr');
        }

        return ['formula_version' => self::ID, 'price_basis' => 'ROW_TOTAL', 'T' => (string) $value];
    }
}
