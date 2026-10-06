<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Every tenant-owned model uses this trait. Queries are always filtered by the
 * current tenant; creating a row without a tenant context fails closed.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->id());
            } else {
                // Fail closed: outside a tenant context a tenant-owned query returns nothing.
                // System jobs opt out explicitly with withoutGlobalScope('tenant').
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if (! $model->tenant_id) {
                if (! $context->has()) {
                    throw new LogicException('Cannot create '.static::class.' without a tenant context');
                }
                $model->tenant_id = $context->id();
            } elseif ($context->has() && (int) $model->tenant_id !== $context->id()) {
                throw new LogicException('Cross-tenant write blocked for '.static::class);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id is immutable');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
