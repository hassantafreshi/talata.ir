<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('invoice_template', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('purpose', 20);                          // OTP | INVOICE | REMINDER
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('schedule_line_id')->nullable();
            $t->string('recipient', 11);
            $t->text('body');
            $t->unsignedSmallInteger('segments');
            $t->decimal('cost_irr', 24, 0)->default(0);
            $t->string('charge_source', 20);                    // OPERATIONAL | FREE_YEARLY | CREDIT | NONE
            $t->string('status', 20);                           // QUEUED | SENDING | SENT | DELIVERED | FAILED | UNKNOWN | AWAITING_CREDIT | CANCELLED
            $t->string('provider_message_id', 80)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error', 250)->nullable();
            $t->string('idempotency_key', 80)->unique();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['recipient', 'created_at']);
            $t->index(['invoice_id', 'created_at']);
            $t->index(['status', 'updated_at']);
        });

        Schema::create('sms_credit_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('source', 20);                           // PURCHASE | PROVIDER_ADJUST
            $t->unsignedBigInteger('source_order_id')->nullable()->unique();
            $t->decimal('amount_irr', 24, 0);
            $t->decimal('remaining_irr', 24, 0);
            $t->boolean('carries_over');
            $t->timestamp('expires_at')->nullable();
            $t->string('plan_at_purchase', 20);
            $t->timestamps();
            $t->index(['tenant_id', 'expires_at']);
        });

        Schema::create('sms_credit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('sms_credit_lots')->nullOnDelete();
            $t->string('type', 10);                             // CREDIT | RESERVE | CAPTURE | RELEASE | EXPIRE | ADJUST
            $t->decimal('amount_irr', 24, 0);
            $t->foreignId('sms_message_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('segments')->nullable();
            $t->decimal('per_segment_irr', 24, 0)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('billing_orders', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('public_ref', 30)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('product', 20);                          // PLAN | SMS_CREDIT
            $t->string('plan_code', 20)->nullable();
            $t->string('period', 10)->nullable();
            $t->decimal('subtotal_irr', 24, 0);
            $t->decimal('vat_rate_percent', 9, 4);
            $t->decimal('vat_irr', 24, 0);
            $t->decimal('amount_irr', 24, 0);
            $t->jsonb('price_snapshot');
            $t->jsonb('return_to')->nullable();
            $t->string('status', 30);                           // CREATED | AWAITING_PAYMENT | VERIFYING | PENDING_VERIFICATION | PAID | FULFILLED | FAILED | EXPIRED
            $t->string('failure_code', 40)->nullable();
            $t->string('failure_message', 250)->nullable();
            $t->string('idempotency_key', 64);
            $t->timestamp('expires_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['status', 'updated_at']);
        });

        Schema::create('payment_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('billing_orders')->cascadeOnDelete();
            $t->string('gateway', 30);
            $t->string('authority', 80);
            $t->decimal('amount_irr', 24, 0);
            $t->string('status', 30);
            $t->string('ref_id', 80)->nullable();
            $t->string('card_mask', 30)->nullable();
            $t->string('bank_code', 20)->nullable();
            $t->jsonb('raw_result_redacted')->nullable();
            $t->unsignedSmallInteger('reconcile_attempts')->default(0);
            $t->timestamp('next_reconcile_at')->nullable();
            $t->timestamp('callback_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['gateway', 'authority']);
        });

        Schema::create('installment_agreements', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('principal_irr', 24, 0);
            $t->decimal('down_payment_irr', 24, 0)->default(0);
            $t->unsignedSmallInteger('count');
            $t->string('frequency', 10);                        // monthly | weekly
            $t->boolean('reminders_enabled')->default(true);
            $t->string('status', 20)->default('active');        // active | completed | cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX installment_agreements_one_active_per_invoice ON installment_agreements (invoice_id) WHERE status = 'active' AND invoice_id IS NOT NULL");
        }

        Schema::create('installment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agreement_id')->constrained('installment_agreements')->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->date('due_date');
            $t->decimal('amount_irr', 24, 0);
            $t->decimal('paid_irr', 24, 0)->default(0);
            $t->timestamps();
            $t->unique(['agreement_id', 'number']);
            $t->index(['tenant_id', 'due_date']);
        });

        Schema::create('installment_payments', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agreement_id')->constrained('installment_agreements')->cascadeOnDelete();
            $t->decimal('amount_irr', 24, 0);
            $t->string('method', 20);                           // cash | pos | card_transfer | other
            $t->date('paid_on');
            $t->string('reference', 60)->nullable();
            $t->jsonb('allocations');
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversal_reason', 250)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('idempotency_key', 64);
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['installment_payments', 'installment_lines', 'installment_agreements', 'payment_attempts', 'billing_orders', 'sms_credit_entries', 'sms_credit_lots', 'sms_messages', 'sms_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
