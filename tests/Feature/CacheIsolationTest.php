<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Ifds\TenantGuard\Cache\CacheBootstrapper;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;

/**
 * Layer 4 - two tenants, one cache key, no collision.
 */
class CacheIsolationTest extends TestCase
{
    public function test_the_explicit_tenant_cache_namespaces_keys(): void
    {
        TenantGuard::set($this->acme);
        TenantGuard::cache()->put('settings', 'acme-value');

        TenantGuard::set($this->globex);
        TenantGuard::cache()->put('settings', 'globex-value');

        $this->assertSame('globex-value', TenantGuard::cache()->get('settings'));

        TenantGuard::set($this->acme);
        $this->assertSame('acme-value', TenantGuard::cache()->get('settings'));
    }

    public function test_the_tenant_cache_refuses_to_build_a_key_without_a_context(): void
    {
        $this->expectException(MissingTenantContextException::class);

        TenantGuard::cache()->get('settings');
    }

    public function test_the_key_includes_the_tenant(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame("tenant:{$this->acme->id}:settings", TenantGuard::cache()->key('settings'));
    }

    public function test_remember_is_scoped(): void
    {
        $value = fn (string $v) => TenantGuard::cache()->remember('report', 60, fn () => $v);

        TenantGuard::set($this->acme);
        $this->assertSame('acme', $value('acme'));

        TenantGuard::set($this->globex);
        $this->assertSame('globex', $value('globex'), 'globex must miss the cache, not read acme');

        TenantGuard::set($this->acme);
        $this->assertSame('acme', $value('should-not-be-used'));
    }

    public function test_forget_only_affects_the_current_tenant(): void
    {
        TenantGuard::set($this->acme);
        TenantGuard::cache()->forever('k', 'a');

        TenantGuard::set($this->globex);
        TenantGuard::cache()->forever('k', 'g');
        TenantGuard::cache()->forget('k');

        $this->assertFalse(TenantGuard::cache()->has('k'));

        TenantGuard::set($this->acme);
        $this->assertSame('a', TenantGuard::cache()->get('k'));
    }

    public function test_the_bootstrapper_swaps_the_global_cache_prefix(): void
    {
        // The array driver ignores prefixes and lives in memory, so it cannot
        // demonstrate prefix isolation. Use a store that actually persists.
        config([
            'cache.default' => 'database',
            'cache.stores.database' => [
                'driver' => 'database',
                'connection' => 'testing',
                'table' => 'cache',
                'lock_connection' => 'testing',
                'lock_table' => 'cache_locks',
            ],
            'cache.prefix' => 'app',
            'tenant-guard.bootstrappers' => [CacheBootstrapper::class => true],
        ]);

        TenantGuard::set($this->acme);
        $this->assertSame("app_tenant_{$this->acme->id}", config('cache.prefix'));

        Cache::put('shared-key', 'acme-value');

        TenantGuard::set($this->globex);
        $this->assertSame("app_tenant_{$this->globex->id}", config('cache.prefix'));

        // Same key, different tenant: must be a miss.
        $this->assertNull(Cache::get('shared-key'));

        Cache::put('shared-key', 'globex-value');
        $this->assertSame('globex-value', Cache::get('shared-key'));

        TenantGuard::set($this->acme);
        $this->assertSame('acme-value', Cache::get('shared-key'));
    }

    public function test_the_bootstrapper_reverts_on_forget(): void
    {
        config([
            'cache.prefix' => 'app',
            'tenant-guard.bootstrappers' => [CacheBootstrapper::class => true],
        ]);

        TenantGuard::set($this->acme);
        TenantGuard::forget();

        $this->assertSame('app', config('cache.prefix'));
    }

    public function test_the_bootstrapper_is_off_by_default(): void
    {
        config(['cache.prefix' => 'app']);

        TenantGuard::set($this->acme);

        $this->assertSame('app', config('cache.prefix'));
    }
}
