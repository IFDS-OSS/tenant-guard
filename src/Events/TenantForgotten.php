<?php

namespace Ifds\TenantGuard\Events;

use Ifds\TenantGuard\Contracts\Tenant;

/**
 * The tenant context was cleared.
 */
class TenantForgotten
{
    public function __construct(public readonly Tenant $tenant)
    {
    }
}
