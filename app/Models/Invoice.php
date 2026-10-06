<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'status', 'direction', 'version', 'rate_mode', 'accepted_rate_irr', 'rate_fetched_at', 'rate_manual_reason',
        'buyer_name', 'buyer_mobile', 'customer_id', 'replaces_invoice_id', 'created_by',
    ];

    protected $hidden = ['verify_token', 'verify_token_hash', 'issue_key'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'verify_token' => 'encrypted', 'rate_fetched_at' => 'datetime', 'issued_at' => 'datetime', 'voided_at' => 'datetime',
            'accepted_rate_irr' => 'string', 'gold_total_irr' => 'string', 'misc_total_irr' => 'string', 'payable_irr' => 'string',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(InvoiceShare::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class)->orderByDesc('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'replaces_invoice_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function replacement(): ?Invoice
    {
        return static::query()->where('replaces_invoice_id', $this->id)->where('status', '!=', 'draft')->first();
    }
}
