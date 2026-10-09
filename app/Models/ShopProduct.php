<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A product the shop has sold before (suggestions and pre-fill on new invoice rows). */
class ShopProduct extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['item_type', 'name', 'name_key', 'kind', 'net_weight_g', 'purity_ppt', 'wage_percent', 'profit_percent', 'manual_total_irr', 'use_count', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'use_count' => 'integer', 'net_weight_g' => 'decimal:6', 'purity_ppt' => 'decimal:3', 'wage_percent' => 'decimal:4', 'profit_percent' => 'decimal:4', 'manual_total_irr' => 'decimal:0'];
    }
}
