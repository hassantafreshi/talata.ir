<?php

namespace App\Models;

use App\Domain\Plans\Entitlements;
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

    /**
     * Members have full access by default; restricting individual permissions is a plan
     * capability (team.permissions_edit). Without it (e.g. Free or after a downgrade) the stored
     * restrictions are kept but not applied, so every member has full access.
     */
    public function can(string $permission): bool
    {
        if ($this->status !== 'active' || ! array_key_exists($permission, self::PERMISSIONS)) {
            return false;
        }
        if ($this->isOwner() || ! $this->restrictionsApply()) {
            return true;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    private ?bool $restrictionsApply = null;

    public function restrictionsApply(): bool
    {
        return $this->restrictionsApply ??= app(Entitlements::class)->can($this->tenant, 'team.permissions_edit');
    }

    /** @return list<string> */
    public static function allPermissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
