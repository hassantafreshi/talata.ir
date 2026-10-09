<?php

namespace App\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasPublicId;

    protected $fillable = ['timezone', 'status', 'suspended_at', 'suspension_reason'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ShopProfile::class)->withoutGlobalScope('tenant');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
