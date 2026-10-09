<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketQuote extends Model
{
    public $timestamps = false;

    /** True for the in-memory quote built from an active provider emergency rate (never saved). */
    public bool $isEmergency = false;

    protected $fillable = ['asset', 'value', 'unit', 'change_vs_previous_pct', 'source', 'is_demo', 'quote_time', 'fetched_at'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'quote_time' => 'datetime', 'fetched_at' => 'datetime', 'change_vs_previous_pct' => 'decimal:4', 'value' => 'decimal:6'];
    }
}
