<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    public const PERMISSIONS = [
        'invoice.issue' => 'ساخت و صدور فاکتور',
        'invoice.void' => 'ابطال و فاکتور جایگزین',
        'customers.manage' => 'مشتریان و ثبت پرداخت',
        'settings.manage' => 'تنظیمات و ظاهر فاکتور',
        'billing.manage' => 'پلن و پرداخت',
    ];

    protected $fillable = ['tenant_id', 'user_id', 'invited_mobile', 'role', 'permissions', 'status', 'invited_by'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function can(string $permission): bool
    {
        return $this->status === 'active' && ($this->isOwner() || in_array($permission, $this->permissions ?? [], true));
    }
}
