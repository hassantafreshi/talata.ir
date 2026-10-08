<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'sms_settings';

    protected $fillable = ['invoice_template', 'auto_send_invoice', 'proforma_valid_hours'];

    protected function casts(): array
    {
        return ['auto_send_invoice' => 'boolean'];
    }

    /** «ارسال خودکار پیامک فاکتور» for the current shop; on unless the shop turned it off. */
    public static function autoSend(): bool
    {
        $value = static::query()->value('auto_send_invoice');

        return $value === null ? true : (bool) $value;
    }
}
