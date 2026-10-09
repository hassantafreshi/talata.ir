<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            // Encrypted, short-lived send payload (OTP code). Erased right after the send attempt;
            // the stored body of an OTP message is always masked.
            $t->text('payload')->nullable();
            $t->string('provider', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', fn (Blueprint $t) => $t->dropColumn(['payload', 'provider']));
    }
};
