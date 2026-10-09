<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceShare extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_id', 'token', 'token_hash', 'created_by', 'revoked_at', 'expires_at'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'expires_at' => 'datetime', 'token' => 'encrypted'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
