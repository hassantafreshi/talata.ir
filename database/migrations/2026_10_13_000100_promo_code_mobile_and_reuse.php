<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $t) {
            // Only this login mobile may use the code (e.g. the owner's own test code); null = anyone.
            $t->string('allowed_mobile', 11)->nullable();
            // false = the same shop may use the code again (test codes); true = one use per shop (default).
            $t->boolean('once_per_shop')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $t) {
            $t->dropColumn(['allowed_mobile', 'once_per_shop']);
        });
    }
};
