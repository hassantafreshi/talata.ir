<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['mobile', 'name'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** Password auth is not used; mobile + OTP only. */
    public function getAuthPassword(): string
    {
        return '';
    }
}
