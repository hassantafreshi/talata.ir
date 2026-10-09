<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned commercial configuration (prices, quotas, capabilities, VAT, SMS credit).
        Schema::create('pricing_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('version')->unique();
            $t->jsonb('payload');
            $t->timestamp('effective_from');
            $t->string('status', 20)->default('published');   // draft | published
            $t->string('note', 250)->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('plan_code', 20);
            $t->string('period', 10);                          // monthly | yearly
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedInteger('carry_over_days')->default(0);
            $t->string('activated_by', 20);                    // PAYMENT | PROVIDER
            $t->unsignedBigInteger('source_order_id')->nullable();
            $t->string('status', 20)->default('active');       // active | superseded
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'ends_at']);
        });

        Schema::create('feature_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('key', 60);
            $t->jsonb('value');
            $t->timestamp('expires_at')->nullable();
            $t->string('reason', 250);
            $t->timestamps();
            $t->index(['tenant_id', 'key']);
        });

        Schema::create('market_quotes', function (Blueprint $t) {
            $t->id();
            $t->string('asset', 20);                           // GOLD_18_BUY | GOLD_18_SELL | GOLD_24 | USD_IRR | XAU_USD
            $t->decimal('value', 30, 6);                       // IRR for local assets, USD for XAU_USD
            $t->string('unit', 30);
            $t->decimal('change_vs_previous_pct', 12, 4)->nullable();
            $t->string('source', 60);
            $t->boolean('is_demo')->default(false);
            $t->timestamp('quote_time')->nullable();
            $t->timestamp('fetched_at');
            $t->index(['asset', 'fetched_at']);
        });

        Schema::create('tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('category', 30);                        // GOLD_SERVICES | MISC | SILVER | COIN | MELTED_GOLD
            $t->unsignedInteger('version');
            $t->decimal('rate_percent', 9, 4);
            $t->string('base', 60);
            $t->timestamp('effective_from');
            $t->timestamp('effective_to')->nullable();
            $t->string('status', 20)->default('active');
            $t->boolean('is_sample')->default(true);
            $t->string('source_reference', 250)->nullable();
            $t->timestamps();
            $t->unique(['category', 'version']);
        });

        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->index();
            $t->foreignId('actor_user_id')->nullable();
            $t->string('actor_type', 20);                      // user | system | provider
            $t->string('event', 60);
            $t->string('subject_type', 40)->nullable();
            $t->string('subject_id', 40)->nullable();
            $t->jsonb('data')->default('{}');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['event', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Append-only audit log enforced in the database itself.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION talata_audit_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_events_no_update BEFORE UPDATE OR DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION talata_audit_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update ON audit_events; DROP FUNCTION IF EXISTS talata_audit_append_only();');
        }
        foreach (['audit_events', 'tax_rules', 'market_quotes', 'feature_overrides', 'subscriptions', 'pricing_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
