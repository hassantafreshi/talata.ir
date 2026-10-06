<?php

namespace App\Domain\Sms;

/** Adapter for an SMS provider (Kavenegar in production; log/fake drivers for development and tests). */
interface SmsGateway
{
    public function name(): string;

    /** @return array{status:'SENT'|'FAILED'|'UNKNOWN',provider_id:?string,error:?string} */
    public function send(string $recipient, string $body): array;

    /**
     * Login code. Providers with an approved OTP template (e.g. Kavenegar Verify Lookup) send only
     * the code; others send $body.
     *
     * @return array{status:'SENT'|'FAILED'|'UNKNOWN',provider_id:?string,error:?string}
     */
    public function sendOtp(string $recipient, string $code, string $body): array;

    /** @return 'SENT'|'DELIVERED'|'FAILED'|'UNKNOWN' */
    public function status(string $providerId): string;
}
