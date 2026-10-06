<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer list computes each row's outstanding installment balance through installment_agreements.customer_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_agreements', function (Blueprint $t) {
            $t->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('installment_agreements', function (Blueprint $t) {
            $t->dropIndex(['customer_id', 'status']);
        });
    }
};
