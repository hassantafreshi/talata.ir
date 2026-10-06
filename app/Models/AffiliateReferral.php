<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends Model
{
    protected $fillable = ['affiliate_id', 'tenant_id', 'source', 'buyer_mobile', 'attributed_at'];

    protected function casts(): array
    {
        return ['attributed_at' => 'datetime'];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
