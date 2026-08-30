<?php

namespace Ifds\TenantGuard\Contracts;

use Closure;

/**
 * The current tenant, for the current process, right now.
 */
interface TenantContext
{
    public function set(object|int|string $tenant): Tenant;

    public function current(): ?Tenant;

    public function id(): int|string|null;

    public function check(): bool;

    /**
     * @throws \Ifds\TenantGuard\Exceptions\MissingTenantContextException
     */
    public function require(): Tenant;

    public function forget(): void;

    /**
     * Run a callback inside another tenant's context, then restore.
     */
    public function runFor(object|int|string $tenant, Closure $callback): mixed;

    /**
     * Run a callback with tenancy suspended. The only sanctioned bypass.
     */
    public function runWithout(Closure $callback): mixed;

    public function isBypassed(): bool;
}
