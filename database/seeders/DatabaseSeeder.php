<?php

namespace Database\Seeders;

use App\Domain\Market\QuoteService;
use App\Models\PricingVersion;
use App\Models\TaxRule;
use Illuminate\Database\Seeder;

/** Production-safe baseline: pricing v1, sample tax rules, first quote fetch. No demo tenants here. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/data/plans-pricing.json'), true);
        unset($payload['$comment']);
        PricingVersion::query()->firstOrCreate(['version' => 1], [
            'payload' => $payload, 'effective_from' => now()->subDay(), 'status' => 'published',
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

        app(QuoteService::class)->refresh();
    }
}
