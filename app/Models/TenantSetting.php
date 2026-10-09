<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Small per-shop settings documents (e.g. invoice_numbering), versioned for optimistic saves and backups. */
class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'value', 'version', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
