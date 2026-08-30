<?php

namespace Ifds\TenantGuard\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Contracts\TenantRepository;
use Ifds\TenantGuard\Queue\TenantQueueBridge;

/**
 * Restores the tenant a job was dispatched under, before the job runs.
 */
class PropagateTenantToJob
{
    public function __construct(
        protected TenantContext $tenancy,
        protected TenantRepository $tenants,
        protected TenantQueueBridge $bridge,
    ) {
    }

    public function handle(JobProcessing $event): void
    {
        if (! $this->bridge->enabled()) {
            return;
        }

        // A worker is long-lived: never inherit the previous job's tenant.
        $this->tenancy->forget();

        $id = $event->job->payload()[$this->bridge->payloadKey()] ?? null;

        if ($id === null) {
            return;
        }

        if ($tenant = $this->tenants->find($id)) {
            $this->tenancy->set($tenant);
        }
    }
}
