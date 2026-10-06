<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin console v1 (docs/handoff/04_SCREENS_ADMIN.md): idempotent staff actions, manual plan
 * activation / payment confirmation, SMS credit adjustments, feature overrides, tenant suspension
 * and the emergency 18K rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per dangerous staff action; the key makes a double submit or a retry apply once.
        Schema::create('admin_actions', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 64)->unique();
            $t->string('action', 40);
            $t->unsignedBigInteger('staff_id');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->jsonb('result');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('emergency_rates', function (Blueprint $t) {
            $t->id();
            $t->string('asset', 20)->default('GOLD_18_SELL');
            $t->decimal('value_irr', 24, 0);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();          // null = until cancelled
            $t->string('reason', 250);
            $t->unsignedBigInteger('created_by_staff');
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by_staff')->nullable();
            $t->timestamps();
            $t->index(['asset', 'cancelled_at', 'starts_at']);
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->timestamp('suspended_at')->nullable();
            $t->string('suspension_reason', 250)->nullable();
        });

        Schema::table('billing_orders', function (Blueprint $t) {
            $t->string('channel', 10)->default('ONLINE');      // ONLINE | MANUAL (recorded by finance)
            $t->unsignedBigInteger('staff_id')->nullable();     // who recorded / confirmed / failed it manually
            $t->string('staff_reason', 250)->nullable();
            $t->string('manual_reference', 60)->nullable();     // bank tracking number of a manual confirmation
            $t->index(['channel', 'created_at']);
        });

        Schema::table('feature_overrides', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by_staff')->nullable();
        });

        // Where an invoice's market rate came from: FEED (central quotes) or EMERGENCY (provider-announced).
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('rate_source', 12)->nullable();
        });

        Schema::table('sms_credit_lots', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by_staff')->nullable();
            $t->string('note', 250)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_credit_lots', fn (Blueprint $t) => $t->dropColumn(['created_by_staff', 'note']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('rate_source'));
        Schema::table('feature_overrides', fn (Blueprint $t) => $t->dropColumn('created_by_staff'));
        Schema::table('billing_orders', function (Blueprint $t) {
            $t->dropIndex(['channel', 'created_at']);
            $t->dropColumn(['channel', 'staff_id', 'staff_reason', 'manual_reference']);
        });
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['suspended_at', 'suspension_reason']));
        Schema::dropIfExists('emergency_rates');
        Schema::dropIfExists('admin_actions');
    }
};
