<?php

namespace Ifds\TenantGuard\Contracts;

/**
 * Reconfigures a piece of framework state whenever the tenant context changes.
 */
interface Bootstrapper
{
    public function bootstrap(Tenant $tenant): void;

    public function revert(): void;
}
