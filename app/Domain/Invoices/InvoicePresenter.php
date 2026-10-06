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
            $c = $r['computed'] ?? [];
            $rows[] = [
                'no' => Digits::toPersian((string) ($i + 1)),
                'type' => $r['item_type'],
                'name' => $r['name'] ?: ($r['item_type'] === 'GOLD' ? 'طلا' : 'متفرقه'),
                'description' => $r['description'],
                'weight' => self::weight($r['net_weight_g']),
                'purity' => $r['item_type'] === 'GOLD' ? self::purityLabel($r['purity_ppt']) : '—',
                'unit_rate' => $r['item_type'] === 'GOLD' && isset($c['effective_rate_irr_per_g']) ? Money::toman((string) BigDecimal::of($c['effective_rate_irr_per_g'])->toScale(0, RoundingMode::HalfUp)) : '—',
                'wage' => $r['item_type'] === 'GOLD' ? Money::toman($c['W'] ?? '0') : '—',
                'wage_percent' => self::percent($r['wage_percent']),
                'profit' => $r['item_type'] === 'GOLD' ? Money::toman($c['P'] ?? '0') : '—',
                'profit_percent' => self::percent($r['profit_percent']),
                'vat' => $r['item_type'] === 'GOLD' ? Money::toman($c['V'] ?? '0') : '—',
                'metal' => $r['item_type'] === 'GOLD' ? Money::toman($c['M'] ?? '0') : null,
                'amount' => Money::toman($r['total_irr']),
            ];
        }
        $gold = $s['totals']['gold_components'];
        $shop = $s['shop'];

        return [
            'number' => Digits::toPersian($s['number']),
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
            'payable_fa' => Money::toman($s['totals']['payable_irr']),
            'layout' => $s['layout'],
            'columns' => LayoutSettings::columnsFor($s['layout'], collect($s['rows'])->contains('item_type', 'GOLD')),
            'show_talata_mark' => (bool) ($s['branding']['show_talata_mark'] ?? true),
        ];
    }
}
