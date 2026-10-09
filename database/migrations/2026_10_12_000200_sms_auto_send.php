<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner decision 2026-10-07: after issuance the invoice SMS goes to the customer automatically; each shop can
// turn that off in settings (docs/INVOICE_DELIVERY_AND_VERIFICATION.md). Default on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $t) {
            $t->boolean('auto_send_invoice')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $t) {
            $t->dropColumn('auto_send_invoice');
        });
    }
};
