<?php

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\SmsGateway;
use Illuminate\Support\Str;

/** Test driver: records messages in memory; outcome is configurable per test. */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to:string,body:string}> */
    public array $sent = [];

    public string $nextStatus = 'SENT';

    public function name(): string
    {
        return 'fake';
    }

    public function send(string $recipient, string $body, ?string $localId = null): array
    {
        $this->sent[] = ['to' => $recipient, 'body' => $body];

        return ['status' => $this->nextStatus, 'provider_id' => 'fake-'.Str::ulid(), 'error' => $this->nextStatus === 'FAILED' ? 'fake failure' : null];
    }

    public function sendOtp(string $recipient, string $code, string $body): array
    {
        return $this->send($recipient, $body);
    }

    public function status(string $providerId): string
    {
        return 'DELIVERED';
    }

    public function statusMany(array $providerIds): array
    {
        return array_combine($providerIds, array_map(fn ($id) => $this->status($id), $providerIds)) ?: [];
    }

    public function lookupLocal(string $localId): ?array
    {
        return null;
    }
}
