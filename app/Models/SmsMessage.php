<?php

namespace App\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** OTP messages have no tenant, so this model is scoped manually via forTenant(). */
class SmsMessage extends Model
{
    use HasPublicId;

    protected $fillable = [
        'tenant_id', 'purpose', 'invoice_id', 'schedule_line_id', 'recipient', 'body', 'segments', 'cost_irr', 'charge_source',
        'status', 'provider_message_id', 'attempts', 'last_error', 'idempotency_key', 'requested_by', 'sent_at', 'delivered_at', 'payload', 'provider',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'cost_irr' => 'decimal:0', 'payload' => 'encrypted:array'];
    }

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    protected $hidden = ['payload'];

    public const FINAL = ['DELIVERED', 'FAILED', 'CANCELLED'];
}
