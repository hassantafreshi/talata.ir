<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Activity log = audit_events (append-only), now attributable per user, per staff member and per service.
        Schema::table('audit_events', function (Blueprint $t) {
            $t->string('service', 30)->default('app');
            $t->foreignId('staff_id')->nullable();
            $t->string('request_id', 26)->nullable();
            $t->string('user_agent', 200)->nullable();
            $t->index(['service', 'created_at']);
            $t->index(['actor_user_id', 'created_at']);
            $t->index(['tenant_id', 'created_at']);
        });

        // Technical log (provider calls, jobs, warnings and exceptions) per service. Admin-only.
        Schema::create('system_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('created_at')->useCurrent();
            $t->string('level', 10);
            $t->string('service', 30);
            $t->string('message', 500);
            $t->jsonb('context')->default('{}');
            $t->string('request_id', 26)->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->index(['service', 'created_at']);
            $t->index(['level', 'created_at']);
            $t->index('request_id');
            $t->index('created_at');
        });

        // Platform administrators (separate from merchant users; separate guard and session).
        Schema::create('staff_users', function (Blueprint $t) {
            $t->id();
            $t->string('mobile', 11)->unique();
            $t->string('name', 80);
            $t->string('role', 20)->default('admin');          // admin | support
            $t->boolean('active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        // WebAuthn credentials for merchants and staff (fingerprint / face / device lock; no biometrics stored).
        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->string('owner_type', 10);                      // user | staff
            $t->unsignedBigInteger('owner_id');
            $t->string('credential_id', 512)->unique();         // base64url
            $t->text('public_key_pem');
            $t->smallInteger('alg');                            // COSE: -7 ES256, -257 RS256
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('name', 60);
            $t->jsonb('transports')->default('[]');
            $t->boolean('backed_up')->default(false);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index(['owner_type', 'owner_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('webauthn_handle', 43)->nullable()->unique(); // opaque user handle, never the mobile
        });
        Schema::table('staff_users', function (Blueprint $t) {
            $t->string('webauthn_handle', 43)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('webauthn_handle'));
        Schema::dropIfExists('passkeys');
        Schema::dropIfExists('staff_users');
        Schema::dropIfExists('system_logs');
        Schema::table('audit_events', function (Blueprint $t) {
            $t->dropIndex(['service', 'created_at']);
            $t->dropIndex(['actor_user_id', 'created_at']);
            $t->dropIndex(['tenant_id', 'created_at']);
            $t->dropColumn(['service', 'staff_id', 'request_id', 'user_agent']);
        });
    }
};
