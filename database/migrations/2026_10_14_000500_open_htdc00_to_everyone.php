<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner request 2026-10-08: the 100% test code HTDC00 is usable by any shop (no longer only 09396727215). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('promo_codes')->where('code', 'HTDC00')->where('allowed_mobile', '09396727215')
            ->update(['allowed_mobile' => null, 'note' => 'کد آزمایشی ۱۰۰٪ — برای همه (درخواست مالک ۱۴۰۵/۰۷/۱۶)', 'updated_at' => now()]);
    }

    public function down(): void {}
};
