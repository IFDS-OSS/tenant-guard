<?php

namespace Ifds\TenantGuard\Events;

/**
 * A guard layer refused an operation. Fired before the exception is thrown, so
 * it reaches your logs even when the exception is caught upstream.
 */
class CrossTenantAccessDenied
{
    public function __construct(
        public readonly string $layer,
        public readonly string $subject,
        public readonly mixed $currentTenant,
        public readonly string $reason,
    ) {
    }
}
