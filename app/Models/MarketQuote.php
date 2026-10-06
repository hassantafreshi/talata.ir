<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketQuote extends Model
{
    public $timestamps = false;

    protected $fillable = ['asset', 'value', 'unit', 'change_vs_previous_pct', 'source', 'is_demo', 'quote_time', 'fetched_at'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'quote_time' => 'datetime', 'fetched_at' => 'datetime'];
    }
}
