<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FeatureOverride extends Model
{
    use BelongsToTenant;

    protected $table = 'feature_overrides';

    protected $fillable = ['key', 'value', 'expires_at', 'reason'];

    protected function casts(): array
    {
        return ['value' => 'array', 'expires_at' => 'datetime'];
    }
}
