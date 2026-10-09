<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own product list (owner request 2026-10-08): every sold product name from issued invoices, with the
 * last weight, purity, wage % and profit % (MISC: last price), so the invoice row can suggest and pre-fill it.
 * Suggestions only — issued invoices keep their own snapshot and are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_products', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 10);                 // GOLD | MISC
            $t->string('name', 120);
            $t->string('name_key', 160);                 // normalized for matching (ProductCatalog::key)
            $t->string('kind', 16)->nullable();          // GOLD: JEWELRY | COIN | MELTED
            $t->decimal('net_weight_g', 18, 6)->nullable();
            $t->decimal('purity_ppt', 9, 3)->nullable();
            $t->decimal('wage_percent', 10, 4)->nullable();
            $t->decimal('profit_percent', 10, 4)->nullable();
            $t->decimal('manual_total_irr', 24, 0)->nullable();
            $t->unsignedInteger('use_count')->default(1);
            $t->timestamp('last_used_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'item_type', 'name_key']);
            $t->index(['tenant_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_products');
    }
};
