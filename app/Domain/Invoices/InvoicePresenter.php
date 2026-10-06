<?php

namespace App\Domain\Invoices;

use App\Models\Invoice;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Display model of an issued invoice, built only from its immutable snapshot. */
final class InvoicePresenter
{
    public const PURITY_LABELS = ['750' => '۱۸ عیار (۷۵۰)', '875' => '۲۱ عیار (۸۷۵)', '1000' => '۲۴ عیار (۱۰۰۰)'];

    public const VOID_REASONS = ['WRONG_WEIGHT' => 'اشتباه در وزن یا عیار', 'CUSTOMER_CANCELLED' => 'انصراف مشتری', 'DUPLICATE' => 'ثبت تکراری', 'OTHER' => 'دلیل دیگر'];

    /** Gold received from the customer (GOLD_IN), by item_attributes.kind. */
    public const GOLD_IN_KINDS = ['OLD_GOLD' => 'طلای کهنه', 'COIN' => 'سکه', 'MELTED' => 'طلای آب‌شده', 'OTHER' => 'طلای دیگر'];

    public const GOLD_IN_BASES = ['BUY' => 'نرخ خرید بازار', 'SELL' => 'نرخ فروش (معاوضه)', 'MANUAL' => 'نرخ دستی'];

    public const RATE_REASONS = ['MARKET_UNAVAILABLE' => 'نرخ بازار در دسترس نیست', 'CUSTOMER_AGREEMENT' => 'توافق با مشتری', 'PEER_RATE' => 'نرخ همکار'];

    public static function purityLabel(?string $ppt): string
    {
        if ($ppt === null) {
            return '—';
        }
        $key = (string) BigDecimal::of($ppt)->strippedOfTrailingZeros();

        return self::PURITY_LABELS[$key] ?? 'عیار '.Digits::toPersian($key);
    }

    public static function weight(?string $g): string
    {
        return $g === null ? '—' : Digits::toPersian((string) BigDecimal::of($g)->strippedOfTrailingZeros());
    }

    public static function percent(?string $p): string
    {
        return $p === null ? '—' : Digits::toPersian((string) BigDecimal::of($p)->strippedOfTrailingZeros()).'٪';
    }

    /** @param bool $public hide full buyer mobile and internal notes */
    public static function present(Invoice $invoice, bool $public = false): array
    {
        $s = $invoice->snapshot;
        $tz = $s['timezone'] ?? config('talata.timezone');
        $issued = CarbonImmutable::parse($s['issued_at']);
        $rows = [];
        foreach ($s['rows'] as $i => $r) {
            $rows[] = self::row($r, $i);
        }
        $gold = $s['totals']['gold_components'];
        $shop = $s['shop'];
        $payable = BigDecimal::of($s['totals']['payable_irr']);
        $hasGoldIn = collect($s['rows'])->contains('item_type', 'GOLD_IN');

        return [
            'number' => Digits::invoiceNumber($s['number']),
            'status' => $invoice->status,
            'issued_fa' => Jalali::date($issued, $tz, true),
            'voided_fa' => $invoice->voided_at ? Jalali::date($invoice->voided_at, $tz) : null,
            'void_reason_fa' => $invoice->void_reason ? (self::VOID_REASONS[$invoice->void_reason] ?? '') : null,
            'issuer' => $public ? '' : ($s['issuer']['name'] ?? ''),
            'shop' => $shop + ['contact_primary' => Digits::toPersian($shop['landline'] ?: Mobile::display($shop['business_mobile'])), 'mobile_display' => Mobile::display($shop['business_mobile'])],
            'buyer_name' => $s['buyer']['name'],
            'buyer_mobile' => $public ? Digits::toPersian(Mobile::mask($s['buyer']['mobile'])) : Mobile::display($s['buyer']['mobile']),
            'rate_fa' => $s['rate']['value_irr'] ? Money::toman($s['rate']['value_irr']) : null,
            'rate_time_fa' => $s['rate']['fetched_at'] ? Jalali::time(CarbonImmutable::parse($s['rate']['fetched_at']), $tz) : null,
            'rate_manual' => $s['rate']['mode'] === 'MANUAL',
            'rate_reason_fa' => self::RATE_REASONS[$s['rate']['manual_reason'] ?? ''] ?? null,
            'tax_rate_fa' => Digits::toPersian((string) BigDecimal::of($s['tax']['rate_percent'])->strippedOfTrailingZeros()),
            'tax_sample' => (bool) ($s['tax']['is_sample'] ?? true),
            'rows' => $rows,
            'has_gold' => collect($s['rows'])->contains('item_type', 'GOLD'),
            'has_misc' => collect($s['rows'])->contains('item_type', 'MISC'),
            'weight_total' => self::weight($gold['weight']),
            'metal_fa' => Money::toman($gold['M']), 'wage_fa' => Money::toman($gold['W']), 'profit_fa' => Money::toman($gold['P']), 'vat_fa' => Money::toman($gold['V']),
            'gold_total_fa' => Money::toman($s['totals']['gold_irr']), 'misc_total_fa' => Money::toman($s['totals']['misc_irr']),
            'payable_fa' => Money::toman((string) $payable->abs()),
            'payable_label' => $payable->isNegative() ? 'مانده به نفع مشتری' : 'قابل پرداخت',
            'customer_credit' => $payable->isNegative(),
            'has_gold_in' => $hasGoldIn,
            'sales_fa' => Money::toman($s['totals']['sales_irr'] ?? (string) BigDecimal::of($s['totals']['gold_irr'])->plus($s['totals']['misc_irr'])),
            'gold_in_fa' => Money::toman($s['totals']['gold_in_irr'] ?? '0'),
            'gold_in_deduction_fa' => Money::toman($s['totals']['gold_in']['D'] ?? '0'),
            'gold_in_has_deduction' => ($s['totals']['gold_in']['D'] ?? '0') !== '0',
            // تفکیک طلایی: 750-equivalent grams sold vs received (older snapshots: derived from rows).
            'w750' => self::weights750($s),
            'layout' => $s['layout'],
            'columns' => LayoutSettings::columnsFor($s['layout'], collect($s['rows'])->contains('item_type', 'GOLD'), $hasGoldIn),
            'show_talata_mark' => (bool) ($s['branding']['show_talata_mark'] ?? true),
        ];
    }

    /** One display row from a snapshot row (GOLD, MISC or GOLD_IN). */
    public static function row(array $r, int $i): array
    {
        $c = $r['computed'] ?? [];
        $type = $r['item_type'];
        $gold = $type === 'GOLD';
        $in = $type === 'GOLD_IN';
        $a = $r['item_attributes'] ?? [];
        $kind = self::GOLD_IN_KINDS[$a['kind'] ?? 'OLD_GOLD'] ?? 'طلای دریافتی';

        return [
            'no' => Digits::toPersian((string) ($i + 1)),
            'type' => $type,
            'direction' => $in ? 'IN' : 'OUT',
            'name' => $r['name'] ?: ($gold ? 'طلا' : ($in ? $kind.' دریافتی از مشتری' : 'متفرقه')),
            'description' => $r['description'],
            'weight' => self::weight($r['net_weight_g']),
            'purity' => $gold || $in ? self::purityLabel($r['purity_ppt']) : '—',
            'purity_short' => $gold || $in ? Digits::toPersian((string) BigDecimal::of($r['purity_ppt'])->strippedOfTrailingZeros()) : '—',
            'weight_750' => $in ? self::weight($c['weight_750'] ?? null) : ($gold ? self::weight(InvoiceCalculator::weight750($r['net_weight_g'], $r['purity_ppt'])) : '—'),
            'unit_rate' => match (true) {
                $gold && isset($c['effective_rate_irr_per_g']) => Money::toman((string) BigDecimal::of($c['effective_rate_irr_per_g'])->toScale(0, RoundingMode::HalfUp)),
                $in && isset($c['rate_irr_per_g']) => Money::toman($c['rate_irr_per_g']),
                default => '—',
            },
            'wage' => $gold ? Money::toman($c['W'] ?? '0') : '—',
            'wage_percent' => self::percent($r['wage_percent']),
            'profit' => $gold ? Money::toman($c['P'] ?? '0') : '—',
            'profit_percent' => self::percent($r['profit_percent']),
            'vat' => $gold ? Money::toman($c['V'] ?? '0') : '—',
            'metal' => $gold ? Money::toman($c['M'] ?? '0') : null,
            // Received gold is credited: shown with a minus sign and labelled «کسر از مبلغ».
            'amount' => $in ? Money::toman('-'.($r['total_irr'] ?? '0')) : Money::toman($r['total_irr']),
            'kind_fa' => $in ? $kind : null,
            'rate_basis_fa' => $in ? (self::GOLD_IN_BASES[$a['rate_basis'] ?? 'BUY'] ?? null) : null,
            'deduction_percent' => $in && ($a['deduction_percent'] ?? '0') !== '0' ? self::percent($a['deduction_percent']) : null,
            'deduction_fa' => $in && ($c['D'] ?? '0') !== '0' ? Money::toman($c['D']) : null,
            'assay_ref' => $in ? ($a['assay_ref'] ?? null) : null,
        ];
    }

    /** @return array{out:string,in:string,net:string,net_label:string} Persian 750-equivalent grams. */
    private static function weights750(array $s): array
    {
        $w = $s['totals']['weights'] ?? null;
        if (! $w) {
            $out = BigDecimal::zero();
            foreach ($s['rows'] as $r) {
                if ($r['item_type'] === 'GOLD') {
                    $out = $out->plus(InvoiceCalculator::weight750($r['net_weight_g'], $r['purity_ppt']));
                }
            }
            $w = ['out_750' => (string) $out, 'in_750' => '0', 'net_750' => (string) $out];
        }
        $net = BigDecimal::of($w['net_750']);

        return [
            'out' => self::weight($w['out_750']), 'in' => self::weight($w['in_750']), 'net' => self::weight((string) $net->abs()),
            'net_label' => $net->isNegative() ? 'طلای دریافتی بیشتر از فروش' : 'خالص طلای تحویل‌شده به مشتری',
        ];
    }
}
