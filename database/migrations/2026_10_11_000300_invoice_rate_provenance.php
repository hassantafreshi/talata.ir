<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where the accepted 18K rate came from, captured at Start (quote id, source, demo flag, freshness, and for a
// manual rate the market value it replaced). Copied into the issued snapshot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->jsonb('rate_provenance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('rate_provenance');
        });
    }
};
