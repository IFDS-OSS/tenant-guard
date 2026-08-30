<?php

namespace Ifds\TenantGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Refuses the request unless a tenant context is already established. Put it
 * after IdentifyTenant on routes that must never run centrally.
 */
class RequireTenant
{
    public function __construct(protected TenantContext $tenancy)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->tenancy->check()) {
            throw new NotFoundHttpException('This route requires a tenant context.');
        }

        return $next($request);
    }
}
