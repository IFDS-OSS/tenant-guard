<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * X-Tenant: acme
 *
 * Convenient for APIs and internal service-to-service calls. Because the value
 * comes from the client, pair it with authorisation - the UserResolver below,
 * or a policy that checks the authenticated token really belongs to that tenant.
 */
class HeaderResolver extends Resolver
{
    public function resolve(Request $request): ?Tenant
    {
        return $this->lookup($request->header($this->setting('header', 'X-Tenant')));
    }
}
