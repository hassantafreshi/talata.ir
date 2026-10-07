<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'row_uid', 'position', 'item_type', 'formula_version', 'name', 'description', 'net_weight_g', 'purity_ppt',
        'wage_percent', 'profit_percent', 'discount_scope', 'discount_irr', 'manual_total_irr', 'item_attributes', 'computed', 'row_total_irr',
    ];

    protected function casts(): array
    {
        return ['item_attributes' => 'array', 'computed' => 'array', 'discount_irr' => 'decimal:0', 'manual_total_irr' => 'decimal:0', 'net_weight_g' => 'decimal:6', 'profit_percent' => 'decimal:4', 'purity_ppt' => 'decimal:3', 'row_total_irr' => 'decimal:0', 'wage_percent' => 'decimal:4'];
    }
}
