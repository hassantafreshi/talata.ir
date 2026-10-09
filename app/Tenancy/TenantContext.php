<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use RuntimeException;

/** Request-scoped current tenant. Set only by the ResolveTenant middleware or explicitly by jobs. */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Membership $membership = null;

    public function set(Tenant $tenant, ?Membership $membership = null): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant in context');
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    /** Runs a callback as a given tenant (jobs, schedulers), restoring the previous context. */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        [$prevTenant, $prevMembership] = [$this->tenant, $this->membership];
        $this->set($tenant);
        try {
            return $callback();
        } finally {
            $this->tenant = $prevTenant;
            $this->membership = $prevMembership;
        }
    }
}
