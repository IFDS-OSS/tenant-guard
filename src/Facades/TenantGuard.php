<?php

namespace Ifds\TenantGuard\Facades;

use Illuminate\Support\Facades\Facade;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * @method static Tenant set(object|int|string $tenant)
 * @method static Tenant|null current()
 * @method static int|string|null id()
 * @method static bool check()
 * @method static Tenant require()
 * @method static Tenant requireTenant()
 * @method static void forget()
 * @method static mixed runFor(object|int|string $tenant, \Closure $callback)
 * @method static mixed runWithout(\Closure $callback)
 * @method static bool isBypassed()
 * @method static void each(\Closure $callback, iterable|null $tenants = null)
 * @method static void reset()
 * @method static \Ifds\TenantGuard\Cache\TenantCacheRepository cache(string|null $store = null)
 * @method static void resolveUsing(\Closure $callback)
 *
 * @see \Ifds\TenantGuard\Tenancy
 */
class TenantGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ifds\TenantGuard\Tenancy::class;
    }
}
