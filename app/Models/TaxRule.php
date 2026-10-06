<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    protected $fillable = ['category', 'version', 'rate_percent', 'base', 'effective_from', 'effective_to', 'status', 'is_sample', 'source_reference'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'is_sample' => 'boolean'];
    }
}
