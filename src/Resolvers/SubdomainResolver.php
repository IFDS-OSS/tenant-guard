<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * acme.app.test -> tenant "acme"
 */
class SubdomainResolver extends Resolver
{
    public function resolve(Request $request): ?Tenant
    {
        $subdomain = $this->subdomainFor($request->getHost());

        if ($subdomain === null) {
            return null;
        }

        if (in_array($subdomain, (array) $this->setting('ignored_subdomains', []), true)) {
            return null;
        }

        return $this->tenants->findBySubdomain($subdomain);
    }

    public function subdomainFor(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        $central = array_map('strtolower', (array) $this->setting('central_domains', []));

        foreach ($central as $domain) {
            if ($host === $domain) {
                return null;
            }

            if (str_ends_with($host, '.'.$domain)) {
                $prefix = substr($host, 0, -(strlen($domain) + 1));

                return $prefix === '' ? null : explode('.', $prefix)[0];
            }
        }

        if ($central !== []) {
            // A host we were told nothing about is not a tenant subdomain.
            return null;
        }

        $labels = explode('.', $host);

        // Needs at least sub.domain.tld before the first label is a subdomain.
        return count($labels) >= 3 ? $labels[0] : null;
    }
}
