<?php

namespace Ifds\TenantGuard\Interop;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Ifds\TenantGuard\Contracts\TenantContext;

/**
 * Keeps Tenant Guard's context in step with whichever tenancy package the host
 * application already uses, by listening to that package's own switch events.
 *
 * Everything here degrades to a no-op when the package is not installed, so the
 * same code path is safe in every application.
 */
class InteropServiceBinder
{
    public function __construct(
        protected TenantContext $tenancy,
        protected Dispatcher $events,
        protected Config $config,
    ) {
    }

    public function register(): void
    {
        if (! $this->config->get('tenant-guard.interop.enabled', true)) {
            return;
        }

        $this->bindSpatie();
        $this->bindStancl();
    }

    /**
     * spatie/laravel-multitenancy fires these when a tenant is made current or
     * forgotten. Names are resolved as strings so the classes need not exist.
     */
    protected function bindSpatie(): void
    {
        if (! SpatieMultitenancyResolver::isAvailable()) {
            return;
        }

        $keyName = $this->config->get('tenant-guard.interop.spatie.key_name');

        $this->events->listen('Spatie\Multitenancy\Events\MadeTenantCurrentEvent', function ($event) use ($keyName) {
            if ($tenant = ($event->tenant ?? null)) {
                $this->tenancy->set(ForeignTenant::wrap($tenant, $keyName));
            }
        });

        $this->events->listen('Spatie\Multitenancy\Events\ForgotCurrentTenantEvent', function () {
            $this->tenancy->forget();
        });
    }

    /**
     * stancl/tenancy fires TenancyInitialized / TenancyEnded around every
     * context switch, including the ones its own middleware performs.
     */
    protected function bindStancl(): void
    {
        if (! StanclTenancyResolver::isAvailable()) {
            return;
        }

        $keyName = $this->config->get('tenant-guard.interop.stancl.key_name');

        $this->events->listen('Stancl\Tenancy\Events\TenancyInitialized', function ($event) use ($keyName) {
            $tenant = $event->tenancy->tenant ?? null;

            if ($tenant !== null) {
                $this->tenancy->set(ForeignTenant::wrap(
                    $tenant,
                    $keyName ?? (method_exists($tenant, 'getTenantKeyName') ? $tenant->getTenantKeyName() : null)
                ));
            }
        });

        $this->events->listen('Stancl\Tenancy\Events\TenancyEnded', function () {
            $this->tenancy->forget();
        });
    }
}
