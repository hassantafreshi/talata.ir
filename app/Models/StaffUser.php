<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Platform administrator (admin console only). Not a merchant user; no tenant. */
class StaffUser extends Authenticatable
{
    public const ROLES = ['admin' => 'مدیر سامانه', 'support' => 'پشتیبانی (فقط مشاهده)'];

    protected $fillable = ['mobile', 'name', 'role', 'active', 'last_login_at', 'webauthn_handle'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
