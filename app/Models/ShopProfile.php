<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ShopProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'business_mobile', 'landline', 'address', 'website', 'socials', 'license_union', 'license_online', 'logo_path', 'logo_version'];

    protected function casts(): array
    {
        return ['socials' => 'array'];
    }

    /** @return list<string> required fields still missing before the first issue */
    public function missing(): array
    {
        return array_values(array_filter(['name', 'business_mobile', 'address'], fn ($f) => blank($this->{$f})));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    public function publicContact(): string
    {
        return $this->landline ?: (string) $this->business_mobile;
    }
}
