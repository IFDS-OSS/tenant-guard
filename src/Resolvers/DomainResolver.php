<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * Full custom domain: shop.acme.com -> the tenant whose `domain` column matches.
 */
class DomainResolver extends Resolver
{
    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());

        if (in_array($host, array_map('strtolower', (array) $this->setting('central_domains', [])), true)) {
            return null;
        }

        return $this->tenants->findByDomain($host);
    }
}
