<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who published a pricing version from the admin console (docs/ADMIN_PRICING.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_versions', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by_staff')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_versions', fn (Blueprint $t) => $t->dropColumn('created_by_staff'));
    }
};
