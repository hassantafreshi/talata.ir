<?php

namespace App\Http\Controllers\App;

use App\Domain\Plans\Entitlements;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

abstract class BaseController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->tenant();
    }

    protected function membership(): Membership
    {
        return app(TenantContext::class)->membership();
    }

    protected function ent(): Entitlements
    {
        return app(Entitlements::class);
    }
}
