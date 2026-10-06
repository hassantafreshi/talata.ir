<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Platform administrator (admin console only). Not a merchant user; no tenant. */
class StaffUser extends Authenticatable
{
    public const ROLES = ['admin' => 'مدیر سامانه (مالک سرویس)', 'finance' => 'مالی', 'ops' => 'عملیات فنی', 'support' => 'پشتیبانی'];

    /**
     * Permission matrix (docs/handoff/04_SCREENS_ADMIN.md A-10). Every role can VIEW the console;
     * these keys gate changes. 'admin' has every permission. Checked per request from a fresh row.
     */
    public const PERMISSIONS = [
        'tenants.manage' => ['label' => 'فعال‌سازی دستی پلن، اعتبار پیامک و قابلیت ویژه', 'roles' => ['finance']],
        'tenants.suspend' => ['label' => 'تعلیق و رفع تعلیق فروشگاه', 'roles' => []],
        'tenants.restore' => ['label' => 'بازگرداندن پشتیبان تنظیمات فروشگاه', 'roles' => ['support']],
        'payments.inquire' => ['label' => 'استعلام دوباره پرداخت از بانک', 'roles' => ['finance', 'ops', 'support']],
        'payments.manage' => ['label' => 'تأیید دستی یا علامت ناموفق پرداخت', 'roles' => ['finance']],
        'pricing.manage' => ['label' => 'تغییر قیمت پلن و پیامک', 'roles' => ['finance']],
        'tax.manage' => ['label' => 'قواعد مالیات', 'roles' => ['finance']],
        'quotes.manage' => ['label' => 'نرخ اضطراری مظنه', 'roles' => ['ops']],
        'sms.manage' => ['label' => 'استعلام پیامک و ارسال آزمایشی', 'roles' => ['ops', 'support']],
        'system.manage' => ['label' => 'اجرای دوباره کارهای ناموفق صف', 'roles' => ['ops']],
        'logs.tech' => ['label' => 'لاگ فنی', 'roles' => ['ops']],
        'affiliates.manage' => ['label' => 'همکاری در فروش (تغییر و واریز)', 'roles' => ['finance']],
        'staff.manage' => ['label' => 'کارکنان و نقش‌ها', 'roles' => []],
    ];

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

    /** Named allows() (not can()) so it never collides with Laravel's authorization helper. */
    public function allows(string $permission): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->isAdmin() || in_array($this->role, self::PERMISSIONS[$permission]['roles'] ?? [], true);
    }
}
