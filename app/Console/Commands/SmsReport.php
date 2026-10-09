<?php

namespace App\Console\Commands;

use App\Domain\Sms\Gateways\KavenegarSmsGateway;
use App\Domain\Sms\SmsGateway;
use App\Models\SmsMessage;
use App\Support\Mobile;
use Illuminate\Console\Command;

/**
 * Recent SMS with our status and, for Kavenegar, the operator's own delivery code and text (read-only lookup,
 * nothing is sent). Numbers are masked. Used by the test-server deploy to diagnose «the customer got nothing».
 */
class SmsReport extends Command
{
    protected $signature = 'talata:sms-report {--hours=48} {--limit=20}';

    protected $description = 'Show recent SMS with our status and the provider delivery status';

    public function handle(SmsGateway $gateway): int
    {
        $rows = SmsMessage::query()->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->orderByDesc('id')->limit((int) $this->option('limit'))->get();
        if ($rows->isEmpty()) {
            $this->line('no SMS in the last '.$this->option('hours').' hours');

            return self::SUCCESS;
        }
        $raw = $gateway instanceof KavenegarSmsGateway ? $gateway->rawStatus($rows->pluck('provider_message_id')->all()) : [];
        foreach ($rows as $m) {
            $p = $raw[(string) $m->provider_message_id] ?? null;
            $this->line(sprintf('%s %-13s to %s seg=%d charge=%-11s ours=%-15s provider_id=%s kavenegar=%s err=%s',
                $m->created_at, $m->purpose, Mobile::mask($m->recipient), $m->segments, $m->charge_source, $m->status,
                $m->provider_message_id ?: '-', $p ? $p['status'].' «'.$p['text'].'»' : '-', mb_substr((string) $m->last_error, 0, 120) ?: '-'));
        }

        return self::SUCCESS;
    }
}
