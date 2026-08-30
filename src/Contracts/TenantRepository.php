<?php

namespace Ifds\TenantGuard\Contracts;

use Illuminate\Support\Collection;

interface TenantRepository
{
    public function find(int|string $key): ?Tenant;

    /**
     * @throws \Ifds\TenantGuard\Exceptions\TenantNotFoundException
     */
    public function findOrFail(int|string $key): Tenant;

    public function findByAttribute(string $attribute, mixed $value): ?Tenant;

    /**
     * Resolve a loose identifier - primary key, slug or domain - to a tenant.
     */
    public function findByIdentifier(int|string $value): ?Tenant;

    /**
     * @throws \Ifds\TenantGuard\Exceptions\TenantNotFoundException
     */
    public function findByIdentifierOrFail(int|string $value): Tenant;

    public function findByDomain(string $domain): ?Tenant;

    public function findBySubdomain(string $subdomain): ?Tenant;

    /** @return Collection<int, Tenant> */
    public function all(): Collection;

    public function flushCache(int|string|null $key = null): void;
}
