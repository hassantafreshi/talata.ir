<?php

namespace App\Domain\Invoices;

use App\Domain\Pricing\GoldInV1;
use App\Domain\Pricing\PolicyRegistry;
use App\Domain\Pricing\PricingError;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * Normalizes raw row input and prices every row with its registered policy.
 * Invalid/incomplete rows are reported per field and never silently ignored.
 */
final class InvoiceCalculator
{
    public const ERRORS_FA = [
        'REQUIRED' => 'این مورد را وارد کنید.',
        'INVALID_NUMBER' => 'عدد درست نیست.',
        'MUST_BE_POSITIVE' => 'باید بیشتر از صفر باشد.',
        'OUT_OF_RANGE' => 'عدد بیش از حد مجاز است.',
        'DISCOUNT_EXCEEDS_ELIGIBLE' => 'برای ادامه، مبلغ تخفیف را کمتر از مجموع اجرت و سود وارد کنید.',
        'DISCOUNT_SCOPE_UNSUPPORTED' => 'نوع تخفیف پشتیبانی نمی‌شود.',
        'ITEM_TYPE_UNSUPPORTED' => 'نوع ردیف پشتیبانی نمی‌شود.',
        'NAME_REQUIRED' => 'عنوان ردیف متفرقه الزامی است.',
        'NO_RATE' => 'برای ردیف طلا ابتدا نرخ ۱۸ عیار لازم است.',
        'NO_BUY_RATE' => 'نرخ خرید بازار در دسترس نیست. «نرخ دستی» را انتخاب و وارد کنید.',
    ];

    /** Row types that are sold to the customer (at least one is required on a sales invoice). */
    public const SALE_TYPES = ['GOLD', 'MISC'];

    public function __construct(private readonly PolicyRegistry $registry) {}

    /** Normalizes one untrusted row from the client into stored columns (strings, IRR). */
    public function normalizeRow(array $raw, int $position): array
    {
        $type = in_array($raw['item_type'] ?? 'GOLD', $this->registry->itemTypes(), true) ? $raw['item_type'] : 'GOLD';
        $uid = preg_match('/^[0-9a-z]{10,26}$/i', (string) ($raw['row_uid'] ?? '')) ? strtolower($raw['row_uid']) : strtolower((string) Str::ulid());
        $text = fn ($v, $max) => ($v = trim(strip_tags((string) $v))) === '' ? null : mb_substr(preg_replace('/\s+/u', ' ', $v), 0, $max);
        $dec = fn ($v) => ($v = Digits::toLatin((string) $v)) === '' ? null : $v;

        $row = [
            'row_uid' => $uid, 'position' => $position, 'item_type' => $type,
            'formula_version' => $this->registry->for($type)->id(),
            'name' => $text($raw['name'] ?? '', 120), 'description' => $text($raw['description'] ?? '', 250),
            'net_weight_g' => null, 'purity_ppt' => null, 'wage_percent' => null, 'profit_percent' => null,
            'discount_scope' => null, 'discount_irr' => null, 'manual_total_irr' => null,
        ];
        if ($type === 'GOLD') {
            $row['net_weight_g'] = $dec($raw['net_weight_g'] ?? '');
            $row['purity_ppt'] = $dec($raw['purity_ppt'] ?? '750') ?? '750';
            $row['wage_percent'] = $dec($raw['wage_percent'] ?? '0') ?? '0';
            $row['profit_percent'] = $dec($raw['profit_percent'] ?? '0') ?? '0';
            $discountToman = $dec($raw['discount_toman'] ?? '');
            if ($discountToman !== null && $discountToman !== '0') {
                $row['discount_scope'] = in_array($raw['discount_scope'] ?? '', ['TAXABLE_COMPONENTS', 'WAGE', 'PROFIT'], true) ? $raw['discount_scope'] : 'TAXABLE_COMPONENTS';
                $row['discount_irr'] = Money::parseTomanToIrr($discountToman, true) ?? 'invalid';
            }
        } elseif ($type === 'GOLD_IN') {
            // Gold the customer gives instead of money (old gold, coin, melted). Valued at an 18K rate, credited against the sale.
            $row['net_weight_g'] = $dec($raw['net_weight_g'] ?? '');
            $row['purity_ppt'] = $dec($raw['purity_ppt'] ?? '750') ?? '750';
            $basis = in_array($raw['rate_basis'] ?? '', GoldInV1::RATE_BASES, true) ? $raw['rate_basis'] : 'BUY';
            $attrs = [
                'kind' => in_array($raw['kind'] ?? '', GoldInV1::KINDS, true) ? $raw['kind'] : 'OLD_GOLD',
                'rate_basis' => $basis,
                'deduction_percent' => $dec($raw['deduction_percent'] ?? '0') ?? '0',
            ];
            if ($basis === 'MANUAL') {
                $attrs['rate_irr_per_g'] = Money::parseTomanToIrr($raw['rate_toman'] ?? '') ?? (trim((string) ($raw['rate_toman'] ?? '')) === '' ? null : 'invalid');
            }
            if ($ref = $text($raw['assay_ref'] ?? '', 40)) {
                $attrs['assay_ref'] = $ref;
            }
            $row['item_attributes'] = $attrs;
        } else {
            $row['manual_total_irr'] = Money::parseTomanToIrr($raw['manual_total_toman'] ?? '') ?? (($raw['manual_total_toman'] ?? '') === '' ? null : 'invalid');
        }

        return $row;
    }

    /**
     * Prices one stored row. Returns ['ok'=>bool,'errors'=>[field=>fa],'computed'=>array|null,'total'=>?string].
     */
    public function priceRow(array $row, ?string $rateIrr, string $vatRatePercent, ?string $buyRateIrr = null): array
    {
        try {
            if ($row['item_type'] === 'GOLD') {
                if (! $rateIrr) {
                    throw new PricingError('NO_RATE', 'price18_irr_per_g');
                }
                if (($row['discount_irr'] ?? null) === 'invalid') {
                    throw new PricingError('INVALID_NUMBER', 'discount');
                }
                $computed = $this->registry->for('GOLD')->price([
                    'net_weight_g' => $row['net_weight_g'], 'purity_ppt' => $row['purity_ppt'], 'price18_irr_per_g' => $rateIrr,
                    'wage_percent' => $row['wage_percent'], 'profit_percent' => $row['profit_percent'],
                    'discount' => $row['discount_irr'] ? ['scope' => $row['discount_scope'], 'amount_irr' => $row['discount_irr']] : null,
                    'vat_rate_percent' => $vatRatePercent,
                ]);
            } elseif ($row['item_type'] === 'GOLD_IN') {
                $a = $row['item_attributes'] ?? [];
                $basis = $a['rate_basis'] ?? 'BUY';
                $rate = match ($basis) {
                    'SELL' => $rateIrr,
                    'MANUAL' => $a['rate_irr_per_g'] ?? null,
                    default => $buyRateIrr,
                };
                if ($basis === 'MANUAL' && ($rate === null || $rate === 'invalid')) {
                    throw new PricingError($rate === null ? 'REQUIRED' : 'INVALID_NUMBER', 'rate_irr_per_g');
                }
                if (! $rate) {
                    throw new PricingError($basis === 'BUY' ? 'NO_BUY_RATE' : 'NO_RATE', 'rate_irr_per_g');
                }
                $computed = $this->registry->for('GOLD_IN')->price([
                    'net_weight_g' => $row['net_weight_g'], 'purity_ppt' => $row['purity_ppt'],
                    'rate_irr_per_g' => $rate, 'deduction_percent' => $a['deduction_percent'] ?? '0',
                ]);
            } else {
                if (! $row['name']) {
                    throw new PricingError('NAME_REQUIRED', 'name');
                }
                $computed = $this->registry->for($row['item_type'])->price(['manual_total_irr' => $row['manual_total_irr'] === 'invalid' ? 'x' : $row['manual_total_irr']]);
            }

            return ['ok' => true, 'errors' => [], 'computed' => $computed, 'total' => $computed['T']];
        } catch (PricingError $e) {
            $field = match ($e->field) {
                'price18_irr_per_g' => 'rate', 'rate_irr_per_g' => 'rate_toman', 'manual_total_irr' => 'manual_total_toman', 'discount' => 'discount_toman', default => $e->field,
            };

            return ['ok' => false, 'errors' => [$field => self::ERRORS_FA[$e->codeName] ?? 'مقدار درست نیست.'], 'computed' => null, 'total' => null];
        }
    }

    /**
     * Prices every row. A sales invoice needs at least one GOLD/MISC row; GOLD_IN rows reduce the payable:
     *   sales = gold_total + misc_total, payable = sales − gold_in_total (negative = balance owed to the customer).
     *
     * @return array{rows:list<array>,valid:bool,sale_required:bool,gold_total:string,misc_total:string,sales_total:string,gold_in_total:string,payable:string,gold:array,gold_in:array,weights:array}
     */
    public function priceAll(array $rows, ?string $rateIrr, string $vatRatePercent, ?string $buyRateIrr = null): array
    {
        $gold = BigInteger::zero();
        $misc = BigInteger::zero();
        $in = BigInteger::zero();
        $agg = ['M' => BigInteger::zero(), 'W' => BigInteger::zero(), 'P' => BigInteger::zero(), 'V' => BigInteger::zero(), 'weight' => '0'];
        $inAgg = ['G' => BigInteger::zero(), 'D' => BigInteger::zero(), 'weight' => BigDecimal::zero(), 'weight_750' => BigDecimal::zero()];
        $out750 = BigDecimal::zero();
        $out = [];
        $valid = count($rows) > 0;
        $hasSale = false;
        foreach ($rows as $row) {
            $hasSale = $hasSale || in_array($row['item_type'], self::SALE_TYPES, true);
            $r = $this->priceRow($row, $rateIrr, $vatRatePercent, $buyRateIrr);
            $valid = $valid && $r['ok'];
            if ($r['ok']) {
                if ($row['item_type'] === 'GOLD') {
                    $gold = $gold->plus($r['total']);
                    foreach (['M', 'W', 'P', 'V'] as $k) {
                        $agg[$k] = $agg[$k]->plus($r['computed'][$k]);
                    }
                    $agg['weight'] = (string) BigDecimal::of($agg['weight'])->plus($row['net_weight_g']);
                    $out750 = $out750->plus(self::weight750($row['net_weight_g'], $row['purity_ppt']));
                } elseif ($row['item_type'] === 'GOLD_IN') {
                    $in = $in->plus($r['total']);
                    $inAgg['G'] = $inAgg['G']->plus($r['computed']['G']);
                    $inAgg['D'] = $inAgg['D']->plus($r['computed']['D']);
                    $inAgg['weight'] = $inAgg['weight']->plus($row['net_weight_g']);
                    $inAgg['weight_750'] = $inAgg['weight_750']->plus($r['computed']['weight_750']);
                } else {
                    $misc = $misc->plus($r['total']);
                }
            }
            $out[] = $row + ['result' => $r];
        }
        $sales = $gold->plus($misc);

        return [
            'rows' => $out, 'valid' => $valid && $hasSale, 'sale_required' => count($rows) > 0 && ! $hasSale,
            'gold_total' => (string) $gold, 'misc_total' => (string) $misc, 'sales_total' => (string) $sales,
            'gold_in_total' => (string) $in, 'payable' => (string) $sales->minus($in),
            'gold' => ['M' => (string) $agg['M'], 'W' => (string) $agg['W'], 'P' => (string) $agg['P'], 'V' => (string) $agg['V'], 'weight' => $agg['weight']],
            'gold_in' => ['G' => (string) $inAgg['G'], 'D' => (string) $inAgg['D'], 'weight' => (string) $inAgg['weight'], 'weight_750' => (string) $inAgg['weight_750']],
            // 750-equivalent grams (per row HALF_UP to 0.001, then summed — the same numbers the invoice rows show).
            'weights' => ['out_750' => (string) $out750, 'in_750' => (string) $inAgg['weight_750'], 'net_750' => (string) $out750->minus($inAgg['weight_750'])],
        ];
    }

    /** 750-equivalent grams of a row, HALF_UP to 0.001 g (the «وزن ۷۵۰» invoice column). */
    public static function weight750(?string $netWeightG, ?string $purityPpt): string
    {
        if ($netWeightG === null || $purityPpt === null || $netWeightG === '' || $purityPpt === '') {
            return '0.000';
        }

        return (string) BigDecimal::of($netWeightG)->multipliedBy($purityPpt)->dividedBy(750, 3, RoundingMode::HalfUp);
    }
}
