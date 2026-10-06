<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sales dashboard: dashboard.view on every plan (owner decision: Free sees sales, wage and gold received
 * for day/week/month); reports.financial keeps unlocking profit, VAT and longer ranges.
 * Never overwrites a value an operator already set.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('pricing_versions')->get() as $row) {
            $payload = json_decode($row->payload, true);
            foreach ($payload['plans'] ?? [] as $code => $plan) {
                $payload['plans'][$code]['capabilities'] += ['dashboard.view' => true];
            }
            DB::table('pricing_versions')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        Cache::forget('talata.pricing.active');
    }

    public function down(): void {}
};
