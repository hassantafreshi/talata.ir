<?php

namespace App\Jobs;

use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Sends one queued message exactly once (claims the row before calling the provider). */
class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $messageId) {}

    public function handle(SmsGateway $gateway, SmsService $service): void
    {
        $claimed = DB::table('sms_messages')->where('id', $this->messageId)->where('status', 'QUEUED')
            ->update(['status' => 'SENDING', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $message = SmsMessage::query()->findOrFail($this->messageId);
        try {
            $result = $gateway->send($message->recipient, $message->body);
            $service->applyOutcome($message, $result['status'], $result['provider_id'], $result['error']);
        } catch (Throwable $e) {
            // Network/provider ambiguity: keep the reservation; reconciliation decides later.
            $service->applyOutcome($message, 'UNKNOWN', null, $e->getMessage());
        }
    }
}
