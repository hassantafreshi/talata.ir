<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Service-wide settings edited in the admin console (not tenant data). */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember('talata.platform.'.$key, 60, fn () => static::query()->find($key)?->value['v'] ?? null);

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $staffId): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'updated_by' => $staffId]);
        Cache::forget('talata.platform.'.$key);
    }

    /** Support phone shown to merchants: admin console value, else TALATA_SUPPORT_PHONE. */
    public static function supportPhone(): ?string
    {
        return static::get('support.phone', config('talata.support.phone')) ?: null;
    }
}
