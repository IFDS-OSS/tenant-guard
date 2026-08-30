<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * /acme/dashboard or a route defined as /{tenant}/dashboard
 */
class PathResolver extends Resolver
{
    public function resolve(Request $request): ?Tenant
    {
        $parameter = $this->setting('route_parameter', 'tenant');

        if ($parameter && ($route = $request->route()) && is_object($route)) {
            $value = $route->parameter($parameter);

            if ($value !== null && $tenant = $this->lookup($value)) {
                return $tenant;
            }
        }

        $segment = $this->setting('path_segment');

        return $segment === null ? null : $this->lookup($request->segment((int) $segment));
    }
}
