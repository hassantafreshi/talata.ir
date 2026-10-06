<?php

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\SmsGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Development driver: writes the message to the log instead of sending it. Never use in production. */
final class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $recipient, string $body): array
    {
        Log::channel('single')->info('[SMS:log driver] to '.$recipient.': '.$body);

        return ['status' => 'SENT', 'provider_id' => 'log-'.Str::ulid(), 'error' => null];
    }

    public function sendOtp(string $recipient, string $code, string $body): array
    {
        return $this->send($recipient, $body);
    }

    public function status(string $providerId): string
    {
        return 'DELIVERED';
    }
}
