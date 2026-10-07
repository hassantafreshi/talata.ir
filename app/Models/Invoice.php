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
        'status', 'direction', 'version', 'rate_mode', 'accepted_rate_irr', 'accepted_buy_rate_irr', 'rate_fetched_at', 'rate_manual_reason', 'rate_source', 'rate_provenance',
        'buyer_name', 'buyer_mobile', 'buyer_national_id', 'customer_id', 'replaces_invoice_id', 'created_by',
    ];

    protected $hidden = ['verify_token', 'verify_token_hash', 'issue_key'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'rate_provenance' => 'array', 'verify_token' => 'encrypted', 'rate_fetched_at' => 'datetime', 'issued_at' => 'datetime', 'voided_at' => 'datetime',
            'accepted_rate_irr' => 'decimal:0', 'accepted_buy_rate_irr' => 'decimal:0', 'gold_total_irr' => 'decimal:0', 'misc_total_irr' => 'decimal:0', 'payable_irr' => 'decimal:0',
            'sales_total_irr' => 'decimal:0', 'gold_in_total_irr' => 'decimal:0', 'wage_irr' => 'decimal:0', 'profit_irr' => 'decimal:0', 'vat_irr' => 'decimal:0',
            'gold_out_weight_750' => 'decimal:3', 'gold_in_weight_750' => 'decimal:3',
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

    /** SMS to the invoice's customer (copies to other numbers are smsCopies). */
    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class)->where('purpose', 'INVOICE')->orderByDesc('id');
    }

    public function smsCopies(): HasMany
    {
        return $this->hasMany(SmsMessage::class)->where('purpose', 'INVOICE_COPY')->orderByDesc('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(InstallmentAgreement::class);
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
