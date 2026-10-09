<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    public const STATUS_FA = ['PENDING' => ['در انتظار تأیید', 'warn'], 'APPROVED' => ['قابل پرداخت', 'info'], 'PAID' => ['پرداخت شد', 'ok'], 'VOID' => ['لغو شد', 'off']];

    protected $fillable = ['affiliate_id', 'referral_id', 'tenant_id', 'order_id', 'product', 'base_irr', 'percent', 'mode', 'amount_irr', 'status', 'approve_after', 'approved_at', 'payout_id', 'void_reason'];

    protected function casts(): array
    {
        return ['approve_after' => 'datetime', 'approved_at' => 'datetime', 'base_irr' => 'decimal:0', 'amount_irr' => 'decimal:0', 'percent' => 'decimal:2'];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferral::class);
    }
}
