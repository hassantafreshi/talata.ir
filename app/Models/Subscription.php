<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'plan_code', 'period', 'starts_at', 'ends_at', 'carry_over_days', 'activated_by', 'source_order_id', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
