<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A real shop whose name reads like a bank/authority (SmsTemplate::impersonatesAuthority) can have that exact
// name approved by Zarlio staff after checking its licence. The hash is of the normalised name, so any change
// to the name needs a new approval.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_profiles', function (Blueprint $t) {
            $t->string('name_approved_hash', 64)->nullable();
            $t->timestamp('name_approved_at')->nullable();
            $t->foreignId('name_approved_by')->nullable()->constrained('staff_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('name_approved_by');
            $t->dropColumn(['name_approved_hash', 'name_approved_at']);
        });
    }
};
