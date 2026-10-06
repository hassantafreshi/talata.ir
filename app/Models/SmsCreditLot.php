<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SmsCreditLot extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'source', 'source_order_id', 'amount_irr', 'remaining_irr', 'carries_over', 'expires_at', 'plan_at_purchase', 'created_by_staff', 'note'];

    protected function casts(): array
    {
        return ['carries_over' => 'boolean', 'expires_at' => 'datetime', 'amount_irr' => 'string', 'remaining_irr' => 'string'];
    }
}
