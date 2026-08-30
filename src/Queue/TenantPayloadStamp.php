<?php

namespace Ifds\TenantGuard\Queue;

use Ifds\TenantGuard\Contracts\TenantContext;

/**
 * The payload hook, as an object rather than a closure, so the bridge can
 * recognise its own registration and never install it twice.
 */
final class TenantPayloadStamp
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly string $key,
    ) {
    }

    public function __invoke(?string $connection, ?string $queue, array $payload): array
    {
        $id = $this->tenancy->id();

        return $id === null ? [] : [$this->key => $id];
    }
}
