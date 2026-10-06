<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentAgreement extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['customer_id', 'invoice_id', 'principal_irr', 'down_payment_irr', 'count', 'frequency', 'reminders_enabled', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['reminders_enabled' => 'boolean', 'principal_irr' => 'string', 'down_payment_irr' => 'string'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InstallmentLine::class, 'agreement_id')->orderBy('number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class, 'agreement_id')->orderByDesc('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
