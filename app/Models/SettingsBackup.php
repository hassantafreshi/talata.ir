<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SettingsBackup extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const REASONS = [
        'profile' => 'تغییر اطلاعات کسب‌وکار', 'logo' => 'تغییر لوگو', 'layout' => 'تغییر ظاهر فاکتور', 'sms_template' => 'تغییر متن پیامک',
        'numbering' => 'تغییر شماره‌گذاری', 'manual' => 'پشتیبان دستی', 'before_restore' => 'پیش از بازگرداندن',
    ];

    protected $fillable = ['reason', 'label', 'payload', 'payload_hash', 'created_by', 'created_by_staff'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
