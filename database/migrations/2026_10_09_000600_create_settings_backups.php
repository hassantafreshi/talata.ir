<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Settings backups: the last 50 settings states per shop, Basic/Professional only (docs/SETTINGS_BACKUPS.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reason', 20);                 // profile | logo | layout | sms_template | numbering | manual | before_restore
            $t->string('label', 80)->nullable();
            $t->jsonb('payload');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('created_by_staff')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'id']);
        });

        foreach (DB::table('pricing_versions')->get() as $row) {
            $payload = json_decode($row->payload, true);
            foreach ($payload['plans'] ?? [] as $code => $plan) {
                $payload['plans'][$code]['capabilities'] += ['settings.backup' => $code !== 'free'];
                $payload['plans'][$code]['quotas'] += ['settings_backups' => 50];
            }
            DB::table('pricing_versions')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        Cache::forget('talata.pricing.active');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_backups');
    }
};
