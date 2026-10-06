<?php

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * GOLD_IR_V1 pricing policy (docs/prompts/PHASE_1_MASTER_PROMPT.md §7).
 * Pure: no HTTP, no Eloquent. Exact rational intermediates, HALF_UP to whole IRR
 * once per posted component (IRR_LINE_HALF_UP_V1). Mirrored by resources/js/pricing.js.
 */
final class GoldIrV1 implements PricingPolicy
{
    public const ID = 'GOLD_IR_V1';

    public const ROUNDING = 'IRR_LINE_HALF_UP_V1';

    public const SCOPES = ['TAXABLE_COMPONENTS', 'WAGE', 'PROFIT'];

    public function __construct(private readonly array $limits = []) {}

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @param  array{net_weight_g:string,purity_ppt:string,price18_irr_per_g:string,wage_percent:string,profit_percent:string,discount:?array{scope:string,amount_irr:string},vat_rate_percent:string}  $in
     * @return array<string,mixed>
     */
    public function price(array $in): array
    {
        $weight = $this->decimal($in['net_weight_g'] ?? null, 'net_weight_g', positive: true, max: $this->limits['max_weight_g'] ?? '100000', maxScale: 6);
        $purity = $this->decimal($in['purity_ppt'] ?? null, 'purity_ppt', positive: true, max: '1000', maxScale: 3);
        $price = $this->decimal($in['price18_irr_per_g'] ?? null, 'price18_irr_per_g', positive: true, max: $this->limits['max_amount_irr'] ?? '1000000000000000', maxScale: 0);
        $wage = $this->decimal($in['wage_percent'] ?? '0', 'wage_percent', positive: false, max: $this->limits['max_percent'] ?? '1000', maxScale: 4);
        $profit = $this->decimal($in['profit_percent'] ?? '0', 'profit_percent', positive: false, max: $this->limits['max_percent'] ?? '1000', maxScale: 4);
        $vat = $this->decimal($in['vat_rate_percent'] ?? null, 'vat_rate_percent', positive: false, max: '100', maxScale: 4);

        $eff = BigRational::of($price)->multipliedBy($purity)->dividedBy(750);
        $mExact = BigRational::of($weight)->multipliedBy($eff);
        $w0Exact = $mExact->multipliedBy($wage)->dividedBy(100);
        $p0Exact = $mExact->plus($w0Exact)->multipliedBy($profit)->dividedBy(100);

        $m = self::round($mExact);
        $w0 = self::round($w0Exact);
        $p0 = self::round($p0Exact);
        $c0 = BigInteger::zero();

        [$allocW, $allocP, $allocC, $scope, $discount] = $this->allocate($in['discount'] ?? null, $w0, $p0, $c0);

        $w = $w0->minus($allocW);
        $p = $p0->minus($allocP);
        $c = $c0->minus($allocC);
        $b = $w->plus($p)->plus($c);
        $v = self::round(BigRational::of($b)->multipliedBy($vat)->dividedBy(100));
        $t = $m->plus($b)->plus($v);

        return [
            'formula_version' => self::ID,
            'rounding_policy' => self::ROUNDING,
            'effective_rate_irr_per_g' => (string) $eff->toScale(18, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
            'M' => (string) $m, 'W0' => (string) $w0, 'P0' => (string) $p0, 'C0' => (string) $c0,
            'discount_scope' => $scope, 'discount_irr' => (string) $discount,
            'allocation_wage' => (string) $allocW, 'allocation_profit' => (string) $allocP, 'allocation_commission' => (string) $allocC,
            'W' => (string) $w, 'P' => (string) $p, 'C' => (string) $c,
            'B' => (string) $b, 'vat_rate_percent' => (string) $vat->strippedOfTrailingZeros(), 'V' => (string) $v, 'T' => (string) $t,
        ];
    }

    /** @return array{0:BigInteger,1:BigInteger,2:BigInteger,3:?string,4:BigInteger} */
    private function allocate(?array $discount, BigInteger $w0, BigInteger $p0, BigInteger $c0): array
    {
        $zero = BigInteger::zero();
        if (! $discount || ($discount['amount_irr'] ?? '') === '' || ($discount['amount_irr'] ?? '0') === '0') {
            return [$zero, $zero, $zero, null, $zero];
        }
        $scope = $discount['scope'] ?? 'TAXABLE_COMPONENTS';
        if (! in_array($scope, self::SCOPES, true)) {
            throw new PricingError('DISCOUNT_SCOPE_UNSUPPORTED', 'discount');
        }
        $amount = $this->decimal($discount['amount_irr'], 'discount', positive: false, max: $this->limits['max_amount_irr'] ?? '1000000000000000', maxScale: 0)->toBigInteger();

        $eligible = match ($scope) {
            'WAGE' => $w0,
            'PROFIT' => $p0,
            default => $w0->plus($p0)->plus($c0),
        };
        if ($amount->isGreaterThan($eligible)) {
            throw new PricingError('DISCOUNT_EXCEEDS_ELIGIBLE', 'discount', ['eligible_irr' => (string) $eligible]);
        }

        if ($scope === 'WAGE') {
            return [$amount, $zero, $zero, $scope, $amount];
        }
        if ($scope === 'PROFIT') {
            return [$zero, $amount, $zero, $scope, $amount];
        }

        // Proportional, largest remainder, stable order wage > profit > commission.
        $components = [$w0, $p0, $c0];
        $floors = [];
        $remainders = [];
        foreach ($components as $i => $component) {
            $num = $amount->multipliedBy($component);
            $floors[$i] = $eligible->isZero() ? $zero : $num->quotient($eligible);
            $remainders[$i] = $eligible->isZero() ? $zero : $num->remainder($eligible);
        }
        $left = $amount->minus($floors[0])->minus($floors[1])->minus($floors[2]);
        $order = [0, 1, 2];
        usort($order, fn ($a, $b) => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);
        foreach ($order as $i) {
            if ($left->isZero()) {
                break;
            }
            $floors[$i] = $floors[$i]->plus(1);
            $left = $left->minus(1);
        }

        return [$floors[0], $floors[1], $floors[2], $scope, $amount];
    }

    public static function round(BigRational $value): BigInteger
    {
        return $value->toScale(0, RoundingMode::HalfUp)->toBigInteger();
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
