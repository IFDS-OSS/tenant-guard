<?php

namespace Ifds\TenantGuard;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantRepository as TenantRepositoryContract;
use Ifds\TenantGuard\Exceptions\TenantNotFoundException;

/**
 * Looks tenants up by key, domain or subdomain, with an optional cache in front
 * so tenant resolution does not cost a query on every request.
 */
class TenantRepository implements TenantRepositoryContract
{
    public function __construct(
        protected Config $config,
        protected CacheFactory $cache,
    ) {
    }

    public function find(int|string $key): ?Tenant
    {
        return $this->remember('key:'.$key, fn () => $this->query()->find($key));
    }

    public function findOrFail(int|string $key): Tenant
    {
        return $this->find($key) ?? throw TenantNotFoundException::forKey($key);
    }

    public function findByAttribute(string $attribute, mixed $value): ?Tenant
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->remember(
            "attr:{$attribute}:".$value,
            fn () => $this->query()->where($attribute, $value)->first()
        );
    }

    public function findByIdentifier(int|string $value): ?Tenant
    {
        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) && $tenant = $this->find($value)) {
            return $tenant;
        }

        return $this->findBySubdomain($value)
            ?? $this->findByDomain($value)
            ?? (ctype_digit($value) ? null : $this->find($value));
    }

    public function findByIdentifierOrFail(int|string $value): Tenant
    {
        return $this->findByIdentifier($value) ?? throw TenantNotFoundException::forKey($value);
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $column = $this->config->get('tenant-guard.resolution.domain_column', 'domain');

        return $this->hasColumn($column) ? $this->findByAttribute($column, $domain) : null;
    }

    public function findBySubdomain(string $subdomain): ?Tenant
    {
        $column = $this->config->get('tenant-guard.resolution.subdomain_column', 'slug');

        return $this->hasColumn($column) ? $this->findByAttribute($column, $subdomain) : null;
    }

    /** @return Collection<int, Tenant> */
    public function all(): Collection
    {
        return $this->query()->get();
    }

    public function flushCache(int|string|null $key = null): void
    {
        if (! $this->cacheEnabled()) {
            return;
        }

        // Stores without tagging support cannot flush selectively; bump a
        // version counter instead so every previous key becomes unreachable.
        if ($key === null) {
            $this->store()->forever($this->versionKey(), $this->version() + 1);

            return;
        }

        $this->store()->forget($this->cacheKey('key:'.$key));
    }

    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->newModel()->newQuery();
    }

    public function newModel(): Model&Tenant
    {
        $class = $this->config->get('tenant-guard.tenant_model', Models\Tenant::class);

        $model = new $class;

        if (! $model instanceof Tenant) {
            throw new \InvalidArgumentException(
                "[{$class}] must implement ".Tenant::class.' to be used as the tenant model.'
            );
        }

        return $model;
    }

    protected function hasColumn(string $column): bool
    {
        $model = $this->newModel();

        return $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    protected function remember(string $key, \Closure $callback): ?Tenant
    {
        if (! $this->cacheEnabled()) {
            return $callback();
        }

        $ttl = (int) $this->config->get('tenant-guard.cache.ttl', 300);

        $tenant = $this->store()->remember($this->cacheKey($key), $ttl, function () use ($callback) {
            // Cache a sentinel rather than null, so misses are cached too.
            return $callback() ?? false;
        });

        return $tenant === false ? null : $tenant;
    }

    protected function cacheEnabled(): bool
    {
        return (bool) $this->config->get('tenant-guard.cache.enabled', true);
    }

    protected function store(): CacheRepository
    {
        return $this->cache->store($this->config->get('tenant-guard.cache.store'));
    }

    protected function cacheKey(string $suffix): string
    {
        $prefix = $this->config->get('tenant-guard.cache.prefix', 'tenant-guard');

        return "{$prefix}:v{$this->version()}:{$suffix}";
    }

    protected function versionKey(): string
    {
        return $this->config->get('tenant-guard.cache.prefix', 'tenant-guard').':version';
    }

    protected function version(): int
    {
        return (int) $this->store()->get($this->versionKey(), 0);
    }
}
