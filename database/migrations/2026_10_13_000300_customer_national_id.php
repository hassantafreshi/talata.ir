<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional Iranian national ID (کد ملی) for customers and on the invoice buyer (owner request 2026-10-07). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('national_id', 10)->nullable();
            $t->unique(['tenant_id', 'national_id']); // one person per national ID in a shop (NULLs allowed)
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('buyer_national_id', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('buyer_national_id'));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'national_id']);
            $t->dropColumn('national_id');
        });
    }
};
