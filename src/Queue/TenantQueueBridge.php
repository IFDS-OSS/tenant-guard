<?php

namespace Ifds\TenantGuard\Queue;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Queue\Queue;
use Ifds\TenantGuard\Contracts\TenantContext;
use ReflectionProperty;

/**
 * Layer 4 - carries the tenant across the queue boundary.
 *
 * Pushing side: stamps the current tenant id into every payload.
 * Worker side: see Listeners\PropagateTenantToJob / ForgetTenantAfterJob.
 *
 * Laravel keeps payload hooks in a static on the Queue class, and several
 * framework helpers flush that static between tests. register() is therefore
 * idempotent *and* re-armable: calling it again after a flush reinstalls the
 * hook, calling it twice in a row does nothing.
 */
class TenantQueueBridge
{
    public function __construct(
        protected TenantContext $tenancy,
        protected Config $config,
    ) {
    }

    public function register(): void
    {
        if (! $this->enabled() || $this->isRegistered()) {
            return;
        }

        Queue::createPayloadUsing(new TenantPayloadStamp($this->tenancy, $this->payloadKey()));
    }

    public function isRegistered(): bool
    {
        foreach ($this->registeredCallbacks() as $callback) {
            if ($callback instanceof TenantPayloadStamp) {
                return true;
            }
        }

        return false;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('tenant-guard.queue.propagate', true);
    }

    public function payloadKey(): string
    {
        return (string) $this->config->get('tenant-guard.queue.payload_key', 'tenant_guard_tenant');
    }

    /**
     * @return list<callable>
     */
    protected function registeredCallbacks(): array
    {
        $property = new ReflectionProperty(Queue::class, 'createPayloadCallbacks');
        $property->setAccessible(true);

        return array_values((array) $property->getValue());
    }
}
