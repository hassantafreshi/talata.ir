<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingOrder extends Model
{
    use BelongsToTenant, HasPublicId;

    public const FINAL = ['FULFILLED', 'FAILED', 'EXPIRED'];

    protected $fillable = [
        'tenant_id', 'public_ref', 'created_by', 'product', 'plan_code', 'period', 'subtotal_irr', 'vat_rate_percent', 'vat_irr', 'amount_irr',
        'price_snapshot', 'return_to', 'status', 'failure_code', 'failure_message', 'idempotency_key', 'expires_at', 'paid_at', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'price_snapshot' => 'array', 'return_to' => 'array', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime',
            'subtotal_irr' => 'string', 'vat_irr' => 'string', 'amount_irr' => 'string',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'order_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
