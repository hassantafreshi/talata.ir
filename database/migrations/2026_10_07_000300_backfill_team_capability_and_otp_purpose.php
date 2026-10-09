<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing pricing versions predate team.permissions_edit / team_members: add the keys
        // (never overwriting a value an operator already set) so restrictions keep applying.
        foreach (DB::table('pricing_versions')->get() as $row) {
            $payload = json_decode($row->payload, true);
            foreach ($payload['plans'] ?? [] as $code => $plan) {
                $payload['plans'][$code]['capabilities'] += ['team.permissions_edit' => $code !== 'free'];
                $payload['plans'][$code]['quotas'] += ['team_members' => 10];
            }
            DB::table('pricing_versions')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        Cache::forget('talata.pricing.active');

        // Staff and merchant login codes are separate ceremonies (own challenge, cooldown and lock).
        Schema::table('otp_challenges', function (Blueprint $t) {
            $t->string('purpose', 10)->default('user');
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', fn (Blueprint $t) => $t->dropColumn('purpose'));
    }
};
