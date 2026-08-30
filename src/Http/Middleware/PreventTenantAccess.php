<?php

namespace Ifds\TenantGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The mirror image of RequireTenant: guards central routes - the marketing
 * site, the ops dashboard, the tenant sign-up flow - against being reached
 * through a tenant hostname.
 */
class PreventTenantAccess
{
    public function __construct(protected TenantContext $tenancy)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->tenancy->check()) {
            throw new NotFoundHttpException('This route is not available on a tenant domain.');
        }

        return $next($request);
    }
}
