<?php

namespace App\Models;

use App\Domain\Plans\Entitlements;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    /**
     * Screen-level access a shop owner can give each team member (docs/TEAM_PERMISSIONS.md).
     * Members get all of them by default; restricting is a plan capability (team.permissions_edit).
     */
    public const PERMISSIONS = [
        'mazneh.view' => 'مظنه',
        'calculator.use' => 'ماشین‌حساب طلایی',
        'invoice.issue' => 'فاکتور جدید (ساخت و صدور)',
        'invoices.view' => 'فاکتورها (فهرست، مشاهده و چاپ همه فاکتورها)',
        'invoice.void' => 'ابطال و فاکتور جایگزین',
        'customers.view' => 'فهرست مشتریان',
        'customers.manage' => 'افزودن و ویرایش مشتری، اقساط و ثبت پرداخت',
        'reports.view' => 'داشبورد و گزارش فروش',
        'settings.manage' => 'تنظیمات فروشگاه و ظاهر فاکتور',
        'billing.manage' => 'خرید پلن و اعتبار پیامک',
    ];

    /** UI groups for the checklist (label => keys). */
    public const GROUPS = [
        'قیمت و محاسبه' => ['mazneh.view', 'calculator.use'],
        'فاکتور' => ['invoice.issue', 'invoices.view', 'invoice.void'],
        'مشتریان' => ['customers.view', 'customers.manage'],
        'مدیریت' => ['reports.view', 'settings.manage', 'billing.manage'],
    ];

    /** One-tap presets for low-literacy owners; the checklist stays editable after choosing one. */
    public const PRESETS = [
        'full' => ['label' => 'دسترسی کامل', 'hint' => 'همه کارها جز مدیریت کاربران', 'permissions' => null],
        'seller' => ['label' => 'فروشنده', 'hint' => 'فاکتور جدید، مظنه، ماشین‌حساب، فهرست مشتریان', 'permissions' => ['mazneh.view', 'calculator.use', 'invoice.issue', 'customers.view']],
        'cashier' => ['label' => 'صندوق‌دار', 'hint' => 'فروشنده + همه فاکتورها و ثبت پرداخت اقساط', 'permissions' => ['mazneh.view', 'calculator.use', 'invoice.issue', 'invoices.view', 'customers.view', 'customers.manage']],
        'prices' => ['label' => 'فقط قیمت', 'hint' => 'مظنه و ماشین‌حساب', 'permissions' => ['mazneh.view', 'calculator.use']],
        'accountant' => ['label' => 'حسابدار', 'hint' => 'فاکتورها، مشتریان و داشبورد؛ بدون صدور', 'permissions' => ['invoices.view', 'customers.view', 'customers.manage', 'reports.view']],
    ];

    /** A permission that needs another to be usable (e.g. voiding needs seeing invoices). */
    public const IMPLIES = [
        'invoice.void' => ['invoices.view'],
        'customers.manage' => ['customers.view'],
    ];

    /** Pages in the order a member lands on after login (first one they can open). */
    public const HOME_ROUTES = [
        'invoice.issue' => 'invoices.new', 'invoices.view' => 'invoices.index', 'mazneh.view' => 'mazneh',
        'calculator.use' => 'calculator', 'reports.view' => 'dashboard', 'customers.view' => 'customers.index',
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
        // Fail closed: if the active pricing version does not define the capability at all,
        // keep applying the stored restrictions rather than silently granting full access.
        return $this->restrictionsApply ??= ! app(Entitlements::class)->defines($this->tenant, 'team.permissions_edit')
            || app(Entitlements::class)->can($this->tenant, 'team.permissions_edit');
    }

    /** @return list<string> */
    public static function allPermissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /** Allowlists, adds implied permissions and keeps catalogue order. @return list<string> */
    public static function normalize(array $input): array
    {
        $set = array_intersect(self::allPermissions(), array_map('strval', $input));
        foreach (self::IMPLIES as $perm => $needs) {
            if (in_array($perm, $set, true)) {
                $set = array_merge($set, $needs);
            }
        }

        return array_values(array_intersect(self::allPermissions(), $set));
    }

    /** Route name of the first page this member may open (settings as the last resort). */
    public function homeRoute(): string
    {
        foreach (self::HOME_ROUTES as $perm => $route) {
            if ($this->can($perm)) {
                return $route;
            }
        }

        return 'settings';
    }
}
