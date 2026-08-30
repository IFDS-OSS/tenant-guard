<?php

namespace Ifds\TenantGuard\Exceptions;

/**
 * Something tenant-owned was touched while no tenant was resolved.
 */
class MissingTenantContextException extends TenantGuardException
{
    public static function forQuery(string $model): self
    {
        return new self(
            "Refusing to query [{$model}]: no tenant context is set. Resolve a tenant with "
            .'TenantGuard::set(), wrap the call in TenantGuard::runFor(), or use '
            .'TenantGuard::runWithout() if crossing tenants is genuinely intended.'
        );
    }

    public static function forWrite(string $model): self
    {
        return new self(
            "Refusing to write [{$model}]: no tenant context is set. A tenant-owned row with a "
            .'null tenant id would be invisible to every tenant.'
        );
    }
}
