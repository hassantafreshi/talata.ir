<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-07: invoices print on A4 landscape by default. Pre-launch, so saved shop layouts are
 * moved to landscape too. Issued invoices are untouched — their layout lives in their own snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_layouts')->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);
            if (! is_array($settings)) {
                return;
            }
            $settings['print'] = array_merge($settings['print'] ?? [], ['orientation' => 'landscape']);
            DB::table('invoice_layouts')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        });
    }

    public function down(): void {}
};
