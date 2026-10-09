<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider-announced 18K rate used while the quote feed is wrong or down (docs/MAZNEH_AND_CALCULATOR.md).
 * Merchants see it labelled «نرخ اعلامی زرلیو (دستی)»; issued invoices keep their captured rate.
 */
class EmergencyRate extends Model
{
    protected $fillable = ['asset', 'value_irr', 'starts_at', 'ends_at', 'reason', 'created_by_staff', 'cancelled_at', 'cancelled_by_staff'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'value_irr' => 'decimal:0'];
    }

    public function scopeActive(Builder $q, string $asset = 'GOLD_18_SELL'): Builder
    {
        return $q->where('asset', $asset)->whereNull('cancelled_at')->where('starts_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isActive(): bool
    {
        return ! $this->cancelled_at && $this->starts_at->lte(now()) && (! $this->ends_at || $this->ends_at->gt(now()));
    }
}
