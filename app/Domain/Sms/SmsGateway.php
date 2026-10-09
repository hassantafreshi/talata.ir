<?php

namespace App\Domain\Sms;

/** Adapter for an SMS provider (Kavenegar in production; log/fake drivers for development and tests). */
interface SmsGateway
{
    public function name(): string;

    /**
     * @param  ?string  $localId  our message id, so an ambiguous send can be looked up later (Kavenegar localid)
     * @return array{status:'SENT'|'FAILED'|'UNKNOWN',provider_id:?string,error:?string}
     */
    public function send(string $recipient, string $body, ?string $localId = null): array;

    /**
     * One-time code. $kind is 'login' or 'mobile_change' (each has its own wording/template, so a
     * number-change code never reads like a login code). Providers with an approved template for the
     * kind (e.g. Kavenegar Verify Lookup) send only the code; others send $body.
     *
     * @return array{status:'SENT'|'FAILED'|'UNKNOWN',provider_id:?string,error:?string}
     */
    public function sendOtp(string $recipient, string $code, string $body, string $kind = 'login'): array;

    /**
     * FAILED = not sent and not charged by the provider; UNDELIVERED = sent (charged) but not delivered.
     *
     * @return 'SENT'|'DELIVERED'|'UNDELIVERED'|'FAILED'|'UNKNOWN'
     */
    public function status(string $providerId): string;

    /**
     * Batched delivery status.
     *
     * @param  list<string>  $providerIds
     * @return array<string,string> provider id => status (as status())
     */
    public function statusMany(array $providerIds): array;

    /**
     * Finds a message by our local id after an ambiguous send (no provider id known).
     *
     * @return ?array{status:string,provider_id:string} null when the provider has no such message
     */
    public function lookupLocal(string $localId): ?array;
}
