<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingVersion extends Model
{
    protected $fillable = ['version', 'payload', 'effective_from', 'status', 'note'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'effective_from' => 'datetime'];
    }
}
