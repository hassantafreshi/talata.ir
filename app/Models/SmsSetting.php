<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'sms_settings';

    protected $fillable = ['invoice_template'];
}
