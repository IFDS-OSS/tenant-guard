<?php

namespace Ifds\TenantGuard\Resolvers;

use Closure;
use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantResolver;

/**
 * Escape hatch for resolution rules that are specific to one application.
 *
 *     TenantGuard::resolveUsing(fn ($request) => Tenant::where(...)->first());
 */
class ClosureResolver implements TenantResolver
{
    public function __construct(protected Closure $callback)
    {
    }

    public function resolve(Request $request): ?Tenant
    {
        $tenant = ($this->callback)($request);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
