<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A person (merchant user) enrolled in the affiliate program by an administrator. Not tenant-scoped. */
class Affiliate extends Model
{
    public const MODES = ['FIRST_PAYMENT' => 'فقط پرداخت اول هر مشتری', 'LIFETIME' => 'همه پرداخت‌ها (مادام‌العمر)'];

    protected $fillable = ['user_id', 'code', 'commission_percent', 'commission_mode', 'discount_percent', 'include_sms_credit', 'status', 'note', 'created_by_staff'];

    protected function casts(): array
    {
        return ['include_sms_credit' => 'boolean', 'commission_percent' => 'decimal:2', 'discount_percent' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function link(): string
    {
        return rtrim((string) config('talata.public_url'), '/').'/r/'.$this->code;
    }
}
