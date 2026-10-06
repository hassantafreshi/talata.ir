<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A verification (QR) token retired by the shop; its page shows «لغوشده» and nothing else. */
class InvoiceVerificationRevocation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['invoice_id', 'token_hash', 'reason', 'revoked_by', 'revoked_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
