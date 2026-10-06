<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantBusinessType extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_business_types';

    protected $fillable = ['business_type', 'enabled', 'selected_at'];
}
