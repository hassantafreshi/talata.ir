<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['name', 'mobile', 'note', 'sms_opt_out', 'created_by'];

    protected function casts(): array
    {
        return ['sms_opt_out' => 'boolean'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(InstallmentAgreement::class);
    }
}
