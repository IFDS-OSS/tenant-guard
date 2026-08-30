<?php

namespace Ifds\TenantGuard\Interop;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantResolver;

/**
 * Follows spatie/laravel-multitenancy.
 *
 * That package answers "which tenant is current?" via
 * Spatie\Multitenancy\Models\Tenant::current(). Adding this resolver to the
 * chain means Tenant Guard's row-level guarantees apply to whatever tenant
 * Spatie has made current - useful when part of your schema is shared even
 * though the rest lives on a per-tenant connection.
 */
class SpatieMultitenancyResolver implements TenantResolver
{
    /** @var class-string */
    public static string $tenantModel = \Spatie\Multitenancy\Models\Tenant::class;

    public function __construct(protected ?string $keyName = null)
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(static::$tenantModel)
            && method_exists(static::$tenantModel, 'current');
    }

    public function resolve(Request $request): ?Tenant
    {
        return $this->current();
    }

    public function current(): ?Tenant
    {
        if (! static::isAvailable()) {
            return null;
        }

        $tenant = (static::$tenantModel)::current();

        return $tenant === null ? null : ForeignTenant::wrap($tenant, $this->keyName);
    }
}
