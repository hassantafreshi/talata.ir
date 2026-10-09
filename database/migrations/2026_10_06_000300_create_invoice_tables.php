<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('mobile', 11)->nullable();
            $t->string('note', 250)->nullable();
            $t->boolean('sms_opt_out')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'mobile']);
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('invoice_layouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->jsonb('settings');
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('invoice_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('jalali_year');
            $t->unsignedInteger('last_seq')->default(0);
            $t->unique(['tenant_id', 'jalali_year']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('draft');        // draft | issued | void
            $t->string('direction', 10)->default('SALE');      // v2-ready: SALE | PURCHASE
            $t->unsignedInteger('version')->default(1);         // optimistic lock for drafts
            $t->string('number', 20)->nullable();
            $t->unsignedSmallInteger('jalali_year')->nullable();
            $t->unsignedInteger('seq')->nullable();
            $t->string('rate_mode', 10)->default('MARKET');    // MARKET | MANUAL | NONE
            $t->decimal('accepted_rate_irr', 24, 0)->nullable();
            $t->timestamp('rate_fetched_at')->nullable();
            $t->string('rate_manual_reason', 30)->nullable();
            $t->string('buyer_name', 80)->nullable();
            $t->string('buyer_mobile', 11)->nullable();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('gold_total_irr', 24, 0)->default(0);
            $t->decimal('misc_total_irr', 24, 0)->default(0);
            $t->decimal('payable_irr', 24, 0)->default(0);
            $t->jsonb('snapshot')->nullable();
            // Lookup by SHA-256 hash; raw token kept only encrypted (APP_KEY) to reprint the same QR.
            $t->char('verify_token_hash', 64)->nullable()->unique();
            $t->text('verify_token')->nullable();
            $t->string('issue_mode', 20)->nullable();
            $t->string('issue_key', 64)->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 30)->nullable();
            $t->string('void_note', 250)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('replaces_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'jalali_year', 'seq']);
            $t->unique(['tenant_id', 'issue_key']);
            $t->index(['tenant_id', 'status', 'issued_at']);
            $t->index(['tenant_id', 'updated_at']);
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('row_uid', 26);
            $t->unsignedSmallInteger('position');
            $t->string('item_type', 10);                        // GOLD | MISC (v2: COIN | SILVER | MELTED_GOLD)
            $t->string('formula_version', 30);                  // GOLD_IR_V1 | MANUAL_LINE_V1
            $t->string('name', 120)->nullable();
            $t->string('description', 250)->nullable();
            $t->decimal('net_weight_g', 18, 6)->nullable();
            $t->decimal('purity_ppt', 9, 3)->nullable();
            $t->decimal('wage_percent', 10, 4)->nullable();
            $t->decimal('profit_percent', 10, 4)->nullable();
            $t->string('discount_scope', 30)->nullable();
            $t->decimal('discount_irr', 24, 0)->nullable();
            $t->decimal('manual_total_irr', 24, 0)->nullable();
            $t->jsonb('item_attributes')->default('{}');        // v2-ready typed attributes
            $t->jsonb('computed')->nullable();
            $t->decimal('row_total_irr', 24, 0)->nullable();
            $t->timestamps();
            $t->unique(['invoice_id', 'row_uid']);
            $t->index(['invoice_id', 'position']);
        });

        // v2-ready: product photos per item (UI not exposed in Phase 1).
        Schema::create('invoice_item_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $t->string('path', 160);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('public')->default(false);
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('invoice_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->text('token');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['invoice_shares', 'invoice_item_assets', 'invoice_items', 'invoices', 'invoice_counters', 'invoice_layouts', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
