<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Staff-made discount code (service-wide, not tenant data). */
class PromoCode extends Model
{
    protected $fillable = ['code', 'percent', 'products', 'max_uses', 'expires_at', 'active', 'note', 'created_by', 'allowed_mobile', 'once_per_shop'];

    protected function casts(): array
    {
        return ['products' => 'array', 'expires_at' => 'datetime', 'active' => 'boolean', 'once_per_shop' => 'boolean', 'percent' => 'decimal:2'];
    }
}
