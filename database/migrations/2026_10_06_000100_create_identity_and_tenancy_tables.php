<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('mobile', 11)->unique();           // normalized 09xxxxxxxxx
            $t->string('name', 80)->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('timezone', 40)->default('Asia/Tehran');
            $t->string('status', 20)->default('active');   // active | suspended
            $t->timestamps();
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('invited_mobile', 11)->nullable();
            $t->string('role', 20);                          // owner | member
            $t->jsonb('permissions')->default('[]');
            $t->string('status', 20)->default('active');     // active | invited | removed
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
            $t->index(['invited_mobile', 'status']);
        });

        Schema::create('shop_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('name', 60)->nullable();
            $t->string('business_mobile', 11)->nullable();
            $t->string('landline', 20)->nullable();
            $t->string('address', 250)->nullable();
            $t->string('website', 120)->nullable();
            $t->jsonb('socials')->default('[]');
            $t->string('license_union', 40)->nullable();
            $t->string('license_online', 40)->nullable();
            $t->string('logo_path', 120)->nullable();
            $t->unsignedInteger('logo_version')->default(0);
            $t->timestamps();
        });

        // v2-ready: business types registry (Phase 1 seeds GOLD_SHOP only).
        Schema::create('tenant_business_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('business_type', 20);                 // GOLD_SHOP | COIN_SHOP | SILVER_SHOP | MELTED_GOLD
            $t->boolean('enabled')->default(true);
            $t->timestamp('selected_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'business_type']);
        });

        Schema::create('otp_challenges', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('mobile', 11)->index();
            $t->string('code_hash', 64);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['otp_challenges', 'tenant_business_types', 'shop_profiles', 'memberships', 'tenants', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
