<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceCounter extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['jalali_year', 'last_seq', 'series', 'period_key'];
}
