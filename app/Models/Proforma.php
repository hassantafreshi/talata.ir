<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** پیش‌فاکتور: see App\Domain\Invoices\ProformaService and docs/PROFORMA.md. */
class Proforma extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['invoice_id', 'jalali_year', 'seq', 'number', 'token', 'token_hash', 'buyer_name', 'buyer_mobile', 'payable_irr', 'snapshot',
        'valid_hours', 'expires_at', 'status', 'confirmed_at', 'issued_at', 'issue_error', 'cancelled_at', 'cancel_reason', 'sent_by'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime', 'payable_irr' => 'decimal:0'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** SENT | EXPIRED | CONFIRMED | CANCELLED — expiry is derived from the time, never a job. */
    public function state(): string
    {
        if ($this->status === 'SENT' && $this->expires_at->isPast()) {
            return 'EXPIRED';
        }

        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->state() === 'SENT';
    }
}
