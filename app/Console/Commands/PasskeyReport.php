<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recent passkey (fingerprint) events from the technical log: browser refusals with the browser's own message
 * and user agent, and server rejections with their reason. Already redacted when written (no numbers, no keys).
 * Used by the test-server deploy so failures on real phones can be read without shell access.
 */
class PasskeyReport extends Command
{
    protected $signature = 'talata:passkey-report {--hours=48} {--limit=20}';

    protected $description = 'Show recent passkey browser errors and server rejections from the technical log';

    public function handle(): int
    {
        $rows = DB::connection(config('database.log_connection'))->table('system_logs')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->where('message', 'like', '%passkey%')
            ->orderByDesc('id')->limit((int) $this->option('limit'))->get(['created_at', 'level', 'service', 'message', 'context']);
        if ($rows->isEmpty()) {
            $this->line('no passkey events in the last '.$this->option('hours').' hours');

            return self::SUCCESS;
        }
        foreach ($rows as $r) {
            $this->line(trim("{$r->created_at} {$r->level} {$r->service}: {$r->message} ".mb_substr((string) $r->context, 0, 600)));
        }
        // Accepted enrolments/sign-ins for comparison (counts only).
        $this->line('passkeys stored: '.DB::table('passkeys')->count().' (user '.DB::table('passkeys')->where('owner_type', 'user')->count().', staff '.DB::table('passkeys')->where('owner_type', 'staff')->count().')');

        return self::SUCCESS;
    }
}
