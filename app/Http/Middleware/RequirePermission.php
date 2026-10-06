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
        $allowed = $permission === '__owner' ? (bool) $membership?->isOwner() : (bool) $membership?->can($permission);
        if (! $allowed) {
            throw new DomainError('FORBIDDEN', 'دسترسی این کار را ندارید. از مالک فروشگاه بخواهید.', 403);
        }

        return $next($request);
    }
}
