<?php

namespace Ifds\TenantGuard\Interop;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantResolver;

/**
 * Follows stancl/tenancy (archtechx).
 *
 * stancl keeps the current tenant on its Tenancy singleton, reachable through
 * the tenancy() helper or the Tenancy class in the container. Tenant Guard then
 * enforces row-level scoping on top - which is exactly what you want for the
 * tables stancl leaves on the central connection.
 */
class StanclTenancyResolver implements TenantResolver
{
    public function __construct(protected ?string $keyName = null)
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(\Stancl\Tenancy\Tenancy::class);
    }

    public function resolve(Request $request): ?Tenant
    {
        return $this->current();
    }

    public function current(): ?Tenant
    {
        if (! static::isAvailable() || ! app()->bound(\Stancl\Tenancy\Tenancy::class)) {
            return null;
        }

        $tenancy = app(\Stancl\Tenancy\Tenancy::class);

        $tenant = $tenancy->tenant ?? null;

        return $tenant === null
            ? null
            : ForeignTenant::wrap($tenant, $this->keyName ?? $this->stanclKeyName($tenant));
    }

    protected function stanclKeyName(object $tenant): ?string
    {
        return method_exists($tenant, 'getTenantKeyName')
            ? $tenant->getTenantKeyName()
            : null;
    }
}
