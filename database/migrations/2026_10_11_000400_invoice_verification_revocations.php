<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retired verification tokens (docs/INVOICE_DELIVERY_AND_VERIFICATION.md: security revocation is a separate,
// authorized, logged action). The old QR then reads «لغوشده» instead of «معتبر»; the invoice gets a new token.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_verification_revocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('reason', 250);
            $t->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_verification_revocations');
    }
};
