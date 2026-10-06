<?php

namespace App\Domain\Sms;

/** Adapter for an SMS provider (provider pending owner selection). */
interface SmsGateway
{
    public function name(): string;

    /** @return array{status:'SENT'|'FAILED'|'UNKNOWN',provider_id:?string,error:?string} */
    public function send(string $recipient, string $body): array;

    /** @return 'SENT'|'DELIVERED'|'FAILED'|'UNKNOWN' */
    public function status(string $providerId): string;
}
