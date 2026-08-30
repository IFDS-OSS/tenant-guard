<?php

namespace Ifds\TenantGuard\Events;

use Ifds\TenantGuard\Contracts\Tenant;

/**
 * Emitted whenever TenantGuard::runWithout() suspends tenancy. Listen to this
 * in production if you want an audit trail of every deliberate bypass.
 */
class TenancyBypassed
{
    public function __construct(
        public readonly ?Tenant $tenant,
        public readonly array $trace = [],
    ) {
    }
}
