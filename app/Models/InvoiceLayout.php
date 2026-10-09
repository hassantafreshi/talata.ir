<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceLayout extends Model
{
    use BelongsToTenant;

    protected $fillable = ['version', 'settings', 'updated_by'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
