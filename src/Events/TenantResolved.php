<?php

namespace Ifds\TenantGuard\Events;

use Ifds\TenantGuard\Contracts\Tenant;

/**
 * A tenant context was established (or switched to).
 */
class TenantResolved
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?Tenant $previous = null,
    ) {
    }
}
