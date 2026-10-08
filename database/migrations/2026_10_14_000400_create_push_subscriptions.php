<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions of shop users' installed web app (owner request 2026-10-08: notify the owner's phone
 * when a customer confirms a پیش‌فاکتور), and the shop's «پیامک به من» switch for the same event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint');
            $t->string('endpoint_hash', 64)->unique();
            $t->string('p256dh', 120);
            $t->string('auth', 60);
            $t->string('device', 120)->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->unsignedSmallInteger('failures')->default(0);
            $t->timestamps();
            $t->index('user_id');
        });
        Schema::table('sms_settings', fn (Blueprint $t) => $t->boolean('proforma_notify_sms')->nullable());   // null = on
    }

    public function down(): void
    {
        Schema::table('sms_settings', fn (Blueprint $t) => $t->dropColumn('proforma_notify_sms'));
        Schema::dropIfExists('push_subscriptions');
    }
};
