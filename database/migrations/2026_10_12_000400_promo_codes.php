<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff-made discount codes (up to 100%, e.g. to test purchases before the bank is connected). Separate from
// affiliate codes; one use per shop; a fully discounted order is fulfilled without the bank (channel FREE).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->decimal('percent', 5, 2);
            $t->json('products');                          // ["PLAN","SMS_CREDIT"]
            $t->unsignedInteger('max_uses')->nullable();   // null = unlimited
            $t->timestamp('expires_at')->nullable();
            $t->boolean('active')->default(true);
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('billing_orders', function (Blueprint $t) {
            $t->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('promo_code_id'));
        Schema::dropIfExists('promo_codes');
    }
};
