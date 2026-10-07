<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agreement_id', 'number', 'due_date', 'amount_irr', 'paid_irr'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount_irr' => 'decimal:0', 'paid_irr' => 'decimal:0'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstallmentAgreement::class, 'agreement_id');
    }

    public function remaining(): string
    {
        return (string) BigInteger::of($this->amount_irr)->minus($this->paid_irr);
    }
}
