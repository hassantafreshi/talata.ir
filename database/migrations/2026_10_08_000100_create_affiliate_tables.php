<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate program (همکاری در فروش). Platform-level data (spans shops), not tenant-scoped.
 * See docs/AFFILIATE_PROGRAM.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();   // one program per person (mobile)
            $t->string('code', 16)->unique();                                     // discount/referral code, uppercase A-Z0-9
            $t->decimal('commission_percent', 5, 2);                              // e.g. 10.00
            $t->string('commission_mode', 20);                                    // FIRST_PAYMENT | LIFETIME
            $t->decimal('discount_percent', 5, 2)->default(0);                    // buyer discount on the first plan purchase
            $t->boolean('include_sms_credit')->default(false);                    // commission on SMS credit purchases too
            $t->string('status', 10)->default('active');                          // active | paused
            $t->string('note', 250)->nullable();
            $t->foreignId('created_by_staff')->nullable();
            $t->timestamps();
        });

        Schema::create('affiliate_referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete(); // a shop belongs to at most one affiliate, forever
            $t->string('source', 10);                                             // LINK | CODE
            $t->string('buyer_mobile', 11);                                       // owner mobile at attribution (shown masked)
            $t->timestamp('attributed_at');
            $t->timestamps();
            $t->index(['affiliate_id', 'attributed_at']);
        });

        Schema::create('affiliate_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount_irr', 24, 0);
            $t->string('reference', 80);                                          // bank transfer tracking number
            $t->foreignId('staff_id')->nullable();
            $t->string('note', 250)->nullable();
            $t->timestamp('paid_at');
            $t->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('referral_id')->constrained('affiliate_referrals')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->unique()->constrained('billing_orders')->cascadeOnDelete(); // one commission per payment
            $t->string('product', 20);
            $t->decimal('base_irr', 24, 0);                                       // pre-VAT amount actually paid
            $t->decimal('percent', 5, 2);                                         // snapshot at payment time
            $t->string('mode', 20);                                               // snapshot
            $t->decimal('amount_irr', 24, 0);
            $t->string('status', 10);                                             // PENDING | APPROVED | PAID | VOID
            $t->timestamp('approve_after');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('payout_id')->nullable()->constrained('affiliate_payouts')->nullOnDelete();
            $t->string('void_reason', 250)->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'status']);
        });

        Schema::table('billing_orders', function (Blueprint $t) {
            $t->decimal('list_subtotal_irr', 24, 0)->nullable();                  // before discount
            $t->decimal('discount_irr', 24, 0)->default(0);
            $t->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $t->string('discount_code', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('affiliate_id');
            $t->dropColumn(['list_subtotal_irr', 'discount_irr', 'discount_code']);
        });
        foreach (['affiliate_commissions', 'affiliate_payouts', 'affiliate_referrals', 'affiliates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
