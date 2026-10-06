<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail. Never pass OTP codes, tokens, keys or full card numbers in $data. */
final class Audit
{
    public static function record(string $event, ?Model $subject = null, array $data = [], ?int $tenantId = null, string $actorType = 'user'): void
    {
        $context = app(TenantContext::class);
        AuditEvent::create([
            'tenant_id' => $tenantId ?? $context->id(),
            'actor_user_id' => $actorType === 'user' ? auth()->id() : null,
            'actor_type' => $actorType,
            'event' => $event,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) ($subject->public_id ?? $subject->getKey()) : null,
            'data' => $data,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
