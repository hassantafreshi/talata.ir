<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Service-wide settings staff change from the admin console (not per shop), e.g. the support phone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
