<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliatePayout extends Model
{
    protected $fillable = ['affiliate_id', 'amount_irr', 'reference', 'staff_id', 'note', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount_irr' => 'decimal:0'];
    }
}
