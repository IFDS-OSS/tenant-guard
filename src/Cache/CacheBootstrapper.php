<?php

namespace Ifds\TenantGuard\Cache;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Ifds\TenantGuard\Contracts\Bootstrapper;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * Layer 4 - namespaces the cache per tenant by swapping `cache.prefix` and
 * discarding the resolved store, so `Cache::get('settings')` cannot return
 * another tenant's value.
 *
 * Opt in via config('tenant-guard.bootstrappers').
 */
class CacheBootstrapper implements Bootstrapper
{
    protected ?string $original = null;

    protected bool $active = false;

    public function __construct(
        protected Container $container,
        protected Config $config,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->original ??= (string) $this->config->get('cache.prefix');

        $this->config->set('cache.prefix', $this->prefixFor($tenant));
        $this->active = true;

        $this->refreshStores();
    }

    public function revert(): void
    {
        if (! $this->active) {
            return;
        }

        $this->config->set('cache.prefix', $this->original);
        $this->active = false;

        $this->refreshStores();
    }

    public function prefixFor(Tenant $tenant): string
    {
        $separator = $this->config->get('tenant-guard.cache.tenant_separator', '_tenant_');

        return $this->original.$separator.$tenant->getTenantKey();
    }

    protected function refreshStores(): void
    {
        $manager = $this->container->make('cache');

        // Stores capture the prefix when they are built, so they have to go.
        if (method_exists($manager, 'forgetDriver')) {
            foreach (array_keys($this->resolvedStores($manager)) as $name) {
                $manager->forgetDriver($name);
            }
        }

        $this->container->forgetInstance('cache.store');
    }

    protected function resolvedStores(object $manager): array
    {
        if (method_exists($manager, 'getStores')) {
            return $manager->getStores();
        }

        // Older managers keep them in a protected property.
        $reflection = new \ReflectionObject($manager);

        if (! $reflection->hasProperty('stores')) {
            return [];
        }

        $property = $reflection->getProperty('stores');
        $property->setAccessible(true);

        return (array) $property->getValue($manager);
    }
}
