<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Contracts\Config\Repository as Config;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantRepository;
use Ifds\TenantGuard\Contracts\TenantResolver;

abstract class Resolver implements TenantResolver
{
    public function __construct(
        protected TenantRepository $tenants,
        protected Config $config,
    ) {
    }

    /**
     * Turn a loose identifier - a primary key, a slug or a domain - into a tenant.
     */
    protected function lookup(mixed $value): ?Tenant
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Tenant) {
            return $value;
        }

        return $this->tenants->findByIdentifier((string) $value);
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->config->get("tenant-guard.resolution.{$key}", $default);
    }
}
