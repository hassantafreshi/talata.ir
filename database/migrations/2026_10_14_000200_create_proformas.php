<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیش‌فاکتور (owner request 2026-10-08, docs/PROFORMA.md): a priced copy of a draft sent to the customer, valid for
 * a set time (default 24 h, chosen by the shop). The customer confirms with their mobile and an SMS code; the
 * draft is then issued as the sales invoice. Each send is its own row, so an old link keeps showing «ابطال شده».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();   // the draft (then the issued invoice)
            $t->unsignedSmallInteger('jalali_year');
            $t->unsignedInteger('seq');
            $t->string('number', 20);
            $t->string('token', 32);                                         // public link code (like InvoiceShare)
            $t->string('token_hash', 64)->unique();
            $t->string('buyer_name', 120)->nullable();
            $t->string('buyer_mobile', 11);
            $t->decimal('payable_irr', 24, 0);
            $t->json('snapshot');
            $t->unsignedSmallInteger('valid_hours');
            $t->timestamp('expires_at');
            $t->string('status', 12)->default('SENT');                       // SENT | CONFIRMED | CANCELLED (expiry is derived)
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('issued_at')->nullable();                          // the sales invoice was issued from it
            $t->string('issue_error', 250)->nullable();                      // why automatic issuance did not happen
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 30)->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'jalali_year', 'seq']);
            $t->index(['tenant_id', 'created_at']);
            $t->index(['invoice_id', 'status']);
        });
        Schema::table('sms_settings', function (Blueprint $t) {
            $t->unsignedSmallInteger('proforma_valid_hours')->nullable();    // the shop's last choice; null = 24
        });
    }

    public function down(): void
    {
        Schema::table('sms_settings', fn (Blueprint $t) => $t->dropColumn('proforma_valid_hours'));
        Schema::dropIfExists('proformas');
    }
};
