<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InstallmentPayment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['agreement_id', 'amount_irr', 'method', 'paid_on', 'reference', 'allocations', 'reversed_at', 'reversal_reason', 'recorded_by', 'idempotency_key'];

    protected function casts(): array
    {
        return ['allocations' => 'array', 'paid_on' => 'date', 'reversed_at' => 'datetime', 'amount_irr' => 'string'];
    }
}
