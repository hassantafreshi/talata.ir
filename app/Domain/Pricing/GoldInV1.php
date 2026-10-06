<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * GOLD_IN_V1: gold the customer hands over instead of money (old gold, coin, melted/assayed gold).
 * The row is valued in 750-equivalent grams at an 18K rate and is credited against the sale:
 *   weight_750 = w × purity / 750
 *   G = round(weight_750 × rate)                 (gross value, whole IRR, HALF_UP)
 *   T = round(weight_750 × rate × (100 − d)/100) (value credited to the customer)
 *   D = G − T                                    (deduction for melting/impurity, shown separately)
 * No wage, profit or VAT: the shop is buying metal, not selling a service (docs/GOLD_RECEIVED_AND_DASHBOARD.md §2).
 * Mirrored by resources/js/pricing.js priceGoldIn().
 */
final class GoldInV1 implements PricingPolicy
{
    public const ID = 'GOLD_IN_V1';

    public const KINDS = ['OLD_GOLD', 'COIN', 'MELTED', 'OTHER'];

    public const RATE_BASES = ['BUY', 'SELL', 'MANUAL'];

    public function __construct(private readonly array $limits = []) {}

    public function id(): string
    {
        return self::ID;
    }

    /** @param array{net_weight_g:string,purity_ppt:string,rate_irr_per_g:string,deduction_percent:string} $in */
    public function price(array $in): array
    {
        $weight = $this->decimal($in['net_weight_g'] ?? null, 'net_weight_g', true, $this->limits['max_weight_g'] ?? '100000', 6);
        $purity = $this->decimal($in['purity_ppt'] ?? null, 'purity_ppt', true, '1000', 3);
        $rate = $this->decimal($in['rate_irr_per_g'] ?? null, 'rate_irr_per_g', true, $this->limits['max_amount_irr'] ?? '1000000000000000', 0);
        $deduction = $this->decimal($in['deduction_percent'] ?? '0', 'deduction_percent', false, (string) ($this->limits['max_gold_in_deduction_percent'] ?? '50'), 4);

        $w750 = BigRational::of($weight)->multipliedBy($purity)->dividedBy(750);
        $gross = $w750->multipliedBy($rate);
        $g = GoldIrV1::round($gross);
        $t = GoldIrV1::round($gross->multipliedBy(BigDecimal::of(100)->minus($deduction))->dividedBy(100));

        return [
            'formula_version' => self::ID,
            'rounding_policy' => GoldIrV1::ROUNDING,
            'direction' => 'IN',
            'weight_750' => (string) $w750->toScale(3, RoundingMode::HalfUp),
            'rate_irr_per_g' => (string) $rate,
            'deduction_percent' => (string) $deduction->strippedOfTrailingZeros(),
            'G' => (string) $g,
            'D' => (string) $g->minus($t),
            'T' => (string) $t,
        ];
    }

    private function decimal(mixed $raw, string $field, bool $positive, string $max, int $maxScale): BigDecimal
    {
        if (! is_string($raw) && ! is_int($raw)) {
            throw new PricingError('REQUIRED', $field);
        }
        $raw = (string) $raw;
        if (! preg_match('/^\d{1,20}(\.\d{1,'.max($maxScale, 1).'})?$/', $raw) || ($maxScale === 0 && str_contains($raw, '.'))) {
            throw new PricingError('INVALID_NUMBER', $field);
        }
        $value = BigDecimal::of($raw);
        if ($positive && $value->isZero()) {
            throw new PricingError('MUST_BE_POSITIVE', $field);
        }
        if ($value->isGreaterThan($max)) {
            throw new PricingError('OUT_OF_RANGE', $field, ['max' => $max]);
        }

        return $value;
    }
}
