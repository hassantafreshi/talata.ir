<?php

namespace App\Http\Middleware;

use App\Domain\DomainError;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $membership = $this->context->membership();
        // "a|b" = any of the listed permissions.
        $allowed = $permission === '__owner' ? (bool) $membership?->isOwner()
            : collect(explode('|', $permission))->contains(fn ($p) => (bool) $membership?->can($p));
        if (! $allowed) {
            throw new DomainError('FORBIDDEN', 'دسترسی این کار را ندارید. از مالک فروشگاه بخواهید.', 403);
        }

        return $next($request);
    }
}
