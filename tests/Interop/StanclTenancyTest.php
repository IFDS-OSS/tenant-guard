<?php

namespace Ifds\TenantGuard\Tests\Interop;

use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Interop\ForeignTenant;
use Ifds\TenantGuard\Interop\StanclTenancyResolver;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Interop\StanclTenant;
use Workbench\App\Models\Post;

/**
 * Compatibility with stancl/tenancy (archtechx).
 *
 * stancl is a connection switcher. Tables it leaves on the central connection -
 * and hybrid setups where the whole schema stays shared - get no row-level
 * protection from it. Tenant Guard supplies that, following stancl's own
 * TenancyInitialized / TenancyEnded events so there is one source of truth.
 */
class StanclTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Stancl\Tenancy\Tenancy::class)) {
            $this->markTestSkipped('stancl/tenancy is not installed.');
        }

        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 4);

        // stancl tenants keyed to match Tenant Guard's tenant ids.
        StanclTenant::create(['id' => (string) $this->acme->id]);
        StanclTenant::create(['id' => (string) $this->globex->id]);

        TenantGuard::forget();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && $this->app->bound(\Stancl\Tenancy\Tenancy::class)) {
            $tenancy = $this->app->make(\Stancl\Tenancy\Tenancy::class);

            if ($tenancy->initialized) {
                $tenancy->end();
            }
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Stancl\Tenancy\TenancyServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.tenant_model', StanclTenant::class);
        $app['config']->set('tenancy.database.central_connection', 'testing');
        // Shared schema: no connection switching, no filesystem or cache swaps.
        $app['config']->set('tenancy.bootstrappers', []);
    }

    protected function stancl(): \Stancl\Tenancy\Tenancy
    {
        return $this->app->make(\Stancl\Tenancy\Tenancy::class);
    }

    public function test_initializing_stancl_tenancy_establishes_tenant_guard_context(): void
    {
        $this->stancl()->initialize(StanclTenant::find((string) $this->acme->id));

        $this->assertTrue(TenantGuard::check());
        $this->assertSame((string) $this->acme->id, (string) TenantGuard::id());
    }

    public function test_queries_are_scoped_by_the_stancl_tenant(): void
    {
        $this->stancl()->initialize(StanclTenant::find((string) $this->acme->id));
        $this->assertSame(2, Post::count());

        $this->stancl()->initialize(StanclTenant::find((string) $this->globex->id));
        $this->assertSame(4, Post::count());
    }

    public function test_writes_are_stamped_with_the_stancl_tenant(): void
    {
        $this->stancl()->initialize(StanclTenant::find((string) $this->globex->id));

        $post = Post::create(['title' => 'Via stancl', 'slug' => 'via-stancl']);

        $this->assertSame((string) $this->globex->id, (string) $post->tenant_id);
    }

    public function test_ending_stancl_tenancy_clears_the_context(): void
    {
        $this->stancl()->initialize(StanclTenant::find((string) $this->acme->id));
        $this->stancl()->end();

        $this->assertFalse(TenantGuard::check());
    }

    public function test_stancl_run_for_scopes_tenant_guard_too(): void
    {
        $tenant = StanclTenant::find((string) $this->globex->id);

        $count = $tenant->run(fn () => Post::count());

        $this->assertSame(4, $count);
    }

    public function test_the_resolver_reports_the_current_stancl_tenant(): void
    {
        $resolver = new StanclTenancyResolver;

        $this->assertNull($resolver->current());

        $this->stancl()->initialize(StanclTenant::find((string) $this->acme->id));

        $this->assertSame((string) $this->acme->id, (string) $resolver->current()->getTenantKey());
    }

    public function test_a_stancl_tenant_can_be_handed_straight_to_tenant_guard(): void
    {
        TenantGuard::set(StanclTenant::find((string) $this->globex->id));

        $this->assertSame(4, Post::count());
    }

    public function test_the_wrapper_reads_stancls_string_key(): void
    {
        $wrapped = ForeignTenant::wrap(StanclTenant::find((string) $this->acme->id));

        $this->assertSame((string) $this->acme->id, (string) $wrapped->getTenantKey());
        $this->assertSame('id', $wrapped->getTenantKeyName());
    }

    public function test_stancl_switching_between_tenants_does_not_bleed(): void
    {
        $acme = StanclTenant::find((string) $this->acme->id);
        $globex = StanclTenant::find((string) $this->globex->id);

        $this->stancl()->initialize($acme);
        $this->assertSame(2, Post::count());

        $this->stancl()->initialize($globex);
        $this->assertSame(4, Post::count());

        $this->stancl()->initialize($acme);
        $this->assertSame(2, Post::count());
    }

    public function test_the_stancl_tenants_table_itself_is_never_scoped(): void
    {
        $this->stancl()->initialize(StanclTenant::find((string) $this->acme->id));

        $this->assertSame(2, StanclTenant::count(), 'stancl\'s own table is central');
    }
}
