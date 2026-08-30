<?php

namespace Ifds\TenantGuard\Tests\Interop;

use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Interop\ForeignTenant;
use Ifds\TenantGuard\Interop\SpatieMultitenancyResolver;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Interop\SpatieTenant;
use Workbench\App\Models\Post;

/**
 * Compatibility with spatie/laravel-multitenancy.
 *
 * Spatie switches connections; Tenant Guard scopes rows. They are complementary,
 * and the point of these tests is that adopting one does not require dropping
 * the other: when Spatie makes a tenant current, Tenant Guard follows.
 */
class SpatieMultitenancyTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Spatie\Multitenancy\Models\Tenant::class)) {
            $this->markTestSkipped('spatie/laravel-multitenancy is not installed.');
        }

        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 4);

        TenantGuard::forget();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Spatie\Multitenancy\MultitenancyServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('multitenancy.tenant_model', SpatieTenant::class);
        // No database switching: this is the shared-schema setup.
        $app['config']->set('multitenancy.switch_tenant_tasks', []);
        $app['config']->set('multitenancy.landlord_database_connection_name', 'testing');
        $app['config']->set('multitenancy.tenant_database_connection_name', 'testing');
    }

    protected function spatieTenant(int|string $key): SpatieTenant
    {
        return SpatieTenant::findOrFail($key);
    }

    public function test_making_a_spatie_tenant_current_establishes_tenant_guard_context(): void
    {
        $this->spatieTenant($this->acme->id)->makeCurrent();

        $this->assertTrue(TenantGuard::check(), 'Tenant Guard should follow Spatie');
        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_queries_are_scoped_by_the_spatie_tenant(): void
    {
        $this->spatieTenant($this->acme->id)->makeCurrent();
        $this->assertSame(2, Post::count());

        $this->spatieTenant($this->globex->id)->makeCurrent();
        $this->assertSame(4, Post::count());
    }

    public function test_writes_are_stamped_with_the_spatie_tenant(): void
    {
        $this->spatieTenant($this->globex->id)->makeCurrent();

        $post = Post::create(['title' => 'Via Spatie', 'slug' => 'via-spatie']);

        $this->assertSame($this->globex->id, $post->tenant_id);
    }

    public function test_forgetting_the_spatie_tenant_clears_the_context(): void
    {
        $this->spatieTenant($this->acme->id)->makeCurrent();
        SpatieTenant::forgetCurrent();

        $this->assertFalse(TenantGuard::check());
    }

    public function test_spatie_execute_scopes_tenant_guard_too(): void
    {
        $count = $this->spatieTenant($this->globex->id)->execute(fn () => Post::count());

        $this->assertSame(4, $count);
    }

    public function test_the_resolver_reports_the_current_spatie_tenant(): void
    {
        SpatieMultitenancyResolver::$tenantModel = SpatieTenant::class;

        $resolver = new SpatieMultitenancyResolver;

        $this->assertNull($resolver->current());

        $this->spatieTenant($this->acme->id)->makeCurrent();

        $this->assertSame($this->acme->id, $resolver->current()->getTenantKey());

        SpatieMultitenancyResolver::$tenantModel = \Spatie\Multitenancy\Models\Tenant::class;
    }

    public function test_a_spatie_tenant_can_be_handed_straight_to_tenant_guard(): void
    {
        $spatie = $this->spatieTenant($this->globex->id);

        // No adapter needed at the call site: set() wraps foreign models itself.
        TenantGuard::set($spatie);

        $this->assertSame($this->globex->id, TenantGuard::id());
        $this->assertSame(4, Post::count());
    }

    public function test_the_wrapper_forwards_attribute_access(): void
    {
        $wrapped = ForeignTenant::wrap($this->spatieTenant($this->acme->id));

        $this->assertSame('acme', $wrapped->slug);
        $this->assertSame($this->acme->id, $wrapped->getTenantKey());
        $this->assertInstanceOf(SpatieTenant::class, $wrapped->unwrap());
    }

    public function test_interop_can_be_switched_off(): void
    {
        config(['tenant-guard.interop.enabled' => false]);

        // The listeners were bound at boot, so prove the switch is respected by
        // rebinding on a fresh binder instance.
        $binder = new \Ifds\TenantGuard\Interop\InteropServiceBinder(
            $this->app->make(\Ifds\TenantGuard\Contracts\TenantContext::class),
            $spy = new \Illuminate\Events\Dispatcher($this->app),
            $this->app['config'],
        );

        $binder->register();

        $this->assertFalse($spy->hasListeners('Spatie\Multitenancy\Events\MadeTenantCurrentEvent'));
    }
}
