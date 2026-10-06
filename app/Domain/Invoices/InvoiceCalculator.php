<?php

namespace App\Domain\Invoices;

use App\Domain\Pricing\PolicyRegistry;
use App\Domain\Pricing\PricingError;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
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
    ];

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
        } else {
            $row['manual_total_irr'] = Money::parseTomanToIrr($raw['manual_total_toman'] ?? '') ?? (($raw['manual_total_toman'] ?? '') === '' ? null : 'invalid');
        }

        return $row;
    }

    /**
     * Prices one stored row. Returns ['ok'=>bool,'errors'=>[field=>fa],'computed'=>array|null,'total'=>?string].
     */
    public function priceRow(array $row, ?string $rateIrr, string $vatRatePercent): array
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
            } else {
                if (! $row['name']) {
                    throw new PricingError('NAME_REQUIRED', 'name');
                }
                $computed = $this->registry->for($row['item_type'])->price(['manual_total_irr' => $row['manual_total_irr'] === 'invalid' ? 'x' : $row['manual_total_irr']]);
            }

            return ['ok' => true, 'errors' => [], 'computed' => $computed, 'total' => $computed['T']];
        } catch (PricingError $e) {
            $field = match ($e->field) {
                'price18_irr_per_g' => 'rate', 'manual_total_irr' => 'manual_total_toman', 'discount' => 'discount_toman', default => $e->field,
            };

            return ['ok' => false, 'errors' => [$field => self::ERRORS_FA[$e->codeName] ?? 'مقدار درست نیست.'], 'computed' => null, 'total' => null];
        }
    }

    /** @return array{rows:list<array>,valid:bool,gold_total:string,misc_total:string,payable:string,gold:array} */
    public function priceAll(array $rows, ?string $rateIrr, string $vatRatePercent): array
    {
        $gold = BigInteger::zero();
        $misc = BigInteger::zero();
        $agg = ['M' => BigInteger::zero(), 'W' => BigInteger::zero(), 'P' => BigInteger::zero(), 'V' => BigInteger::zero(), 'weight' => '0'];
        $out = [];
        $valid = count($rows) > 0;
        foreach ($rows as $row) {
            $r = $this->priceRow($row, $rateIrr, $vatRatePercent);
            $valid = $valid && $r['ok'];
            if ($r['ok']) {
                if ($row['item_type'] === 'GOLD') {
                    $gold = $gold->plus($r['total']);
                    foreach (['M', 'W', 'P', 'V'] as $k) {
                        $agg[$k] = $agg[$k]->plus($r['computed'][$k]);
                    }
                    $agg['weight'] = (string) BigDecimal::of($agg['weight'])->plus($row['net_weight_g']);
                } else {
                    $misc = $misc->plus($r['total']);
                }
            }
            $out[] = $row + ['result' => $r];
        }

        return [
            'rows' => $out, 'valid' => $valid,
            'gold_total' => (string) $gold, 'misc_total' => (string) $misc, 'payable' => (string) $gold->plus($misc),
            'gold' => ['M' => (string) $agg['M'], 'W' => (string) $agg['W'], 'P' => (string) $agg['P'], 'V' => (string) $agg['V'], 'weight' => $agg['weight']],
        ];
    }
}
