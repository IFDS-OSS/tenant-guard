<?php

namespace Ifds\TenantGuard\Listeners;

use Ifds\TenantGuard\Contracts\TenantContext;

/**
 * Clears the context once a job finishes, so nothing bleeds into the next one.
 */
class ForgetTenantAfterJob
{
    public function __construct(protected TenantContext $tenancy)
    {
    }

    public function handle(object $event): void
    {
        $this->tenancy->forget();
    }
}
