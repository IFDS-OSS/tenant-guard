<?php

namespace Ifds\TenantGuard\Cache;

use Illuminate\Contracts\Cache\Repository;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;

/**
 * An explicitly tenant-scoped view over any cache repository, for teams that
 * would rather namespace a few keys by hand than swap the global prefix.
 *
 *     TenantGuard::cache()->remember('settings', 60, fn () => ...);
 */
class TenantCacheRepository
{
    public function __construct(
        protected Repository $store,
        protected TenantContext $tenancy,
    ) {
    }

    public function key(string $key): string
    {
        $id = $this->tenancy->id();

        if ($id === null) {
            throw new MissingTenantContextException(
                "Refusing to build a tenant cache key for [{$key}] without a tenant context."
            );
        }

        return "tenant:{$id}:{$key}";
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return $this->store->put($this->key($key), $value, $ttl);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->key($key), $value);
    }

    public function remember(string $key, mixed $ttl, \Closure $callback): mixed
    {
        return $this->store->remember($this->key($key), $ttl, $callback);
    }

    public function rememberForever(string $key, \Closure $callback): mixed
    {
        return $this->store->rememberForever($this->key($key), $callback);
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->key($key));
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($this->key($key));
    }

    public function store(): Repository
    {
        return $this->store;
    }
}
