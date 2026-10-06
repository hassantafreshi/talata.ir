<?php

namespace App\Http\Controllers\App;

use App\Domain\Invoices\LayoutSettings;
use App\Models\ShopProfile;
use App\Support\Mobile;

/** Sample invoice (labelled) for the appearance editor preview. Never consumes quota. */
final class SamplePreview
{
    public static function invoice(?ShopProfile $p, array $layout, bool $logo, bool $mark, string $tenantPublicId): array
    {
        return [
            'number' => '۱۴۰۵-۰۰۱۲', 'status' => 'issued', 'issued_fa' => '۱۴۰۵/۰۷/۱۳ · ۱۴:۲۵', 'voided_fa' => null, 'void_reason_fa' => null, 'issuer' => 'نمونه',
            'shop' => [
                'name' => $p?->name ?: 'نام فروشگاه شما', 'address' => $p?->address ?: 'آدرس کسب‌وکار', 'website' => $p?->website, 'socials' => $p?->socials ?? [],
                'license_union' => $p?->license_union, 'license_online' => $p?->license_online, 'landline' => $p?->landline, 'business_mobile' => $p?->business_mobile,
                'contact_primary' => $p?->landline ?: Mobile::display($p?->business_mobile) ?: 'تلفن', 'mobile_display' => Mobile::display($p?->business_mobile),
                'logo' => $logo && $p?->logo_path ? ['tenant' => $tenantPublicId, 'version' => $p->logo_version] : null,
            ],
            'buyer_name' => 'خانم رضایی (نمونه)', 'buyer_mobile' => '۰۹۱۲ ۰۰۰ ۰۰۰۰',
            'rate_fa' => '۱۰٬۰۰۰٬۰۰۰', 'rate_time_fa' => '۱۴:۲۱', 'rate_manual' => false, 'rate_reason_fa' => null, 'tax_rate_fa' => '۱۰', 'tax_sample' => true,
            'rows' => [
                ['no' => '۱', 'type' => 'GOLD', 'name' => 'النگو ۱۸ عیار', 'description' => 'طرح نمونه', 'weight' => '۲', 'purity' => '۱۸ عیار (۷۵۰)', 'unit_rate' => '۱۰٬۰۰۰٬۰۰۰', 'wage' => '۴۰۰٬۰۰۰', 'wage_percent' => '۲٪', 'profit' => '۱٬۰۲۰٬۰۰۰', 'profit_percent' => '۵٪', 'vat' => '۱۴۲٬۰۰۰', 'metal' => '۲۰٬۰۰۰٬۰۰۰', 'amount' => '۲۱٬۵۶۲٬۰۰۰'],
                ['no' => '۲', 'type' => 'MISC', 'name' => 'جعبه هدیه', 'description' => null, 'weight' => '—', 'purity' => '—', 'unit_rate' => '—', 'wage' => '—', 'wage_percent' => '—', 'profit' => '—', 'profit_percent' => '—', 'vat' => '—', 'metal' => null, 'amount' => '۲۰۰٬۰۰۰'],
            ],
            'has_gold' => true, 'has_misc' => true, 'weight_total' => '۲',
            'metal_fa' => '۲۰٬۰۰۰٬۰۰۰', 'wage_fa' => '۴۰۰٬۰۰۰', 'profit_fa' => '۱٬۰۲۰٬۰۰۰', 'vat_fa' => '۱۴۲٬۰۰۰',
            'gold_total_fa' => '۲۱٬۵۶۲٬۰۰۰', 'misc_total_fa' => '۲۰۰٬۰۰۰', 'payable_fa' => '۲۱٬۷۶۲٬۰۰۰',
            'layout' => $layout, 'columns' => LayoutSettings::columnsFor($layout, true), 'show_talata_mark' => $mark,
        ];
    }
}
