<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only ledger row. */
class SmsCreditEntry extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'lot_id', 'type', 'amount_irr', 'sms_message_id', 'segments', 'per_segment_irr', 'created_at'];
}
