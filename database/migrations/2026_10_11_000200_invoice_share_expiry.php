<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional expiry for customer share links (TALATA_SHARE_TTL_DAYS; empty = never, the default).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_shares', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_shares', function (Blueprint $t) {
            $t->dropColumn('expires_at');
        });
    }
};
