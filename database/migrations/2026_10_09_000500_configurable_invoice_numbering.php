<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable invoice numbering (docs/INVOICE_NUMBERING.md) + a generic per-shop settings store.
 * Counters are keyed by (series, period_key) instead of only the Jalali year, and uniqueness moves to the
 * printed number itself, so monthly / continuous schemes never collide. Existing numbers are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->jsonb('value');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });

        Schema::table('invoice_counters', function (Blueprint $t) {
            $t->string('series', 10)->default('SALE');
            $t->string('period_key', 12)->nullable();
        });
        DB::table('invoice_counters')->update(['period_key' => DB::raw('CAST(jalali_year AS VARCHAR(12))')]);
        Schema::table('invoice_counters', function (Blueprint $t) {
            $t->unsignedSmallInteger('jalali_year')->nullable()->change();
            $t->dropUnique(['tenant_id', 'jalali_year']);
            $t->unique(['tenant_id', 'series', 'period_key']);
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->string('number', 32)->nullable()->change();
            $t->dropUnique(['tenant_id', 'jalali_year', 'seq']);
            $t->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'jalali_year', 'seq']);
        });
        Schema::table('invoice_counters', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'series', 'period_key']);
            $t->unique(['tenant_id', 'jalali_year']);
            $t->dropColumn(['series', 'period_key']);
        });
        Schema::dropIfExists('tenant_settings');
    }
};
