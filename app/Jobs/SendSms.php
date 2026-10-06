<?php

namespace App\Jobs;

use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\SmsMessage;
use App\Support\TechLog;
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
        $message->forceFill(['provider' => $gateway->name()])->save();
        try {
            if ($message->purpose === 'OTP') {
                $code = (string) ($message->payload['code'] ?? '');
                $kind = ($message->payload['kind'] ?? 'login') === 'mobile_change' ? 'mobile_change' : 'login';
                $result = $gateway->sendOtp($message->recipient, $code, SmsService::otpBody($code, $kind), $kind);
            } else {
                $result = $gateway->send($message->recipient, $message->body, $message->public_id);
            }
            $service->applyOutcome($message, $result['status'], $result['provider_id'], $result['error']);
            TechLog::write($result['status'] === 'SENT' ? 'info' : 'warning', 'sms', 'sms '.strtolower($message->purpose).' '.strtolower($result['status']), [
                'message' => $message->public_id, 'tenant_id' => $message->tenant_id, 'provider' => $gateway->name(),
                'provider_id' => $result['provider_id'], 'segments' => $message->segments, 'receptor' => TechLog::scrub($message->recipient), 'error' => $result['error'],
            ]);
        } catch (Throwable $e) {
            // Network/provider ambiguity: keep the reservation; reconciliation decides later.
            $service->applyOutcome($message, 'UNKNOWN', null, TechLog::scrub($e->getMessage()));
            TechLog::error('sms', 'sms send exception', ['message' => $message->public_id, 'error' => mb_substr(TechLog::scrub($e->getMessage()), 0, 300)]);
        } finally {
            // The login code is single-use; never keep it after the attempt (tries = 1).
            DB::table('sms_messages')->where('id', $message->id)->update(['payload' => null]);
        }
    }
}
