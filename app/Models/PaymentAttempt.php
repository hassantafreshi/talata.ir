<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reached by the bank callback without a session; always loaded through its unique (gateway, authority). */
class PaymentAttempt extends Model
{
    protected $fillable = ['order_id', 'gateway', 'authority', 'amount_irr', 'status', 'ref_id', 'card_mask', 'bank_code', 'raw_result_redacted', 'reconcile_attempts', 'next_reconcile_at', 'callback_at', 'verified_at'];

    protected function casts(): array
    {
        return ['raw_result_redacted' => 'array', 'next_reconcile_at' => 'datetime', 'callback_at' => 'datetime', 'verified_at' => 'datetime', 'amount_irr' => 'decimal:0'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'order_id')->withoutGlobalScope('tenant');
    }
}
