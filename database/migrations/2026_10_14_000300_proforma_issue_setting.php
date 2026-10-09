<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * پیش‌فاکتور setting (owner decision 2026-10-08): after the customer confirms, the sales invoice is issued
 * automatically (default) or by the shop. Changing it is the capability proforma.configure — granted wherever
 * invoice.customize is (Basic and Professional), never overwriting a value an operator already set. Each
 * پیش‌فاکتور keeps the choice it was sent with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', fn (Blueprint $t) => $t->boolean('proforma_auto_issue')->nullable());   // null = automatic
        Schema::table('proformas', fn (Blueprint $t) => $t->boolean('auto_issue')->default(true));

        foreach (DB::table('pricing_versions')->get() as $row) {
            $payload = json_decode($row->payload, true);
            foreach ($payload['plans'] ?? [] as $code => $plan) {
                $payload['plans'][$code]['capabilities'] += ['proforma.configure' => (bool) ($plan['capabilities']['invoice.customize'] ?? false)];
            }
            DB::table('pricing_versions')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        Cache::forget('talata.pricing.active');
    }

    public function down(): void
    {
        Schema::table('proformas', fn (Blueprint $t) => $t->dropColumn('auto_issue'));
        Schema::table('sms_settings', fn (Blueprint $t) => $t->dropColumn('proforma_auto_issue'));
    }
};
