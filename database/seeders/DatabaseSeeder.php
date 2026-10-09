<?php

namespace Database\Seeders;

use App\Domain\Market\QuoteService;
use App\Models\PricingVersion;
use App\Models\PromoCode;
use App\Models\SmsCreditLot;
use App\Models\TaxRule;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/** Production-safe baseline: pricing v1, sample tax rules, first quote fetch. No demo tenants here. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/data/plans-pricing.json'), true);
        unset($payload['$comment']);
        PricingVersion::query()->firstOrCreate(['version' => 1], [
            // Fixed past start (like the tax rules): «now − 1 day» made tests that travel to a fixed date fail
            // depending on the time of day they ran.
            'payload' => $payload, 'effective_from' => '2025-03-21 00:00:00', 'status' => 'published',
            'note' => 'Seed from docs/PLANS_AND_QUOTAS.md (Basic monthly and Basic caps pending owner confirmation).',
        ]);

        TaxRule::query()->firstOrCreate(['category' => 'GOLD_SERVICES', 'version' => 1], [
            'rate_percent' => '10', 'base' => 'WAGE+PROFIT+COMMISSION', 'effective_from' => '2025-03-21 00:00:00',
            'status' => 'active', 'is_sample' => true, 'source_reference' => 'نمونه؛ تأیید کارشناس مالیاتی لازم است',
        ]);
        TaxRule::query()->firstOrCreate(['category' => 'MISC', 'version' => 1], [
            'rate_percent' => '0', 'base' => 'NONE', 'effective_from' => '2025-03-21 00:00:00', 'status' => 'active', 'is_sample' => true,
            'source_reference' => 'قیمت ردیف متفرقه نهایی است؛ مالیات جدا محاسبه نمی‌شود',
        ]);

        // Owner's test code (owner request 2026-10-07; opened to everyone 2026-10-08): 100% off plan purchases,
        // any shop, any number of times. Created once; deactivate, cap or set an expiry in the admin console.
        PromoCode::query()->firstOrCreate(['code' => 'HTDC00'], [
            'percent' => '100', 'products' => ['PLAN'], 'max_uses' => null, 'expires_at' => null, 'active' => true,
            'allowed_mobile' => null, 'once_per_shop' => false, 'note' => 'کد آزمایشی ۱۰۰٪ — برای همه (درخواست مالک ۱۴۰۵/۰۷/۱۶)',
        ]);

        app(QuoteService::class)->refresh();

        $this->seedStarterSmsCreditForExistingShops();
    }

    /**
     * Non-production only: top up existing shops that have never received the test starter credit, so invoice
     * SMS can be exercised on the test server without a purchase. Idempotent (a marker lot is created once per
     * shop); a no-op in production and whenever TALATA_STARTER_SMS_CREDIT_TOMAN is unset.
     */
    private function seedStarterSmsCreditForExistingShops(): void
    {
        $toman = (int) config('talata.sms.starter_credit_toman');
        if ($toman <= 0 || app()->isProduction()) {
            return;
        }
        $note = 'اعتبار آزمایشی اولیه (محیط تست)';
        Tenant::query()->each(function (Tenant $tenant) use ($toman, $note) {
            $already = SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('note', $note)->exists();
            if ($already) {
                return;
            }
            SmsCreditLot::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id, 'source' => 'PROVIDER_ADJUST', 'amount_irr' => (string) ($toman * 10), 'remaining_irr' => (string) ($toman * 10),
                'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'free', 'note' => $note,
            ]);
        });
    }
}
