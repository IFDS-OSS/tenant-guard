<?php

namespace Ifds\TenantGuard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Sql\TenantTableRegistry;
use Ifds\TenantGuard\Testing\InteractsWithTenancy;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;
    use WithWorkbench;

    protected Tenant $acme;

    protected Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        // Touch every guarded model so its table is registered with the
        // sentinel, exactly as it would be in a booted application.
        foreach ([Post::class, Comment::class, User::class] as $model) {
            new $model;
        }

        $this->acme = Tenant::factory()->named('acme', 'acme.example.com')->create();
        $this->globex = Tenant::factory()->named('globex', 'globex.example.com')->create();

        $this->bootQueuePropagation();
        $this->tenancy()->reset();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(TenantContext::class)->reset();
            $this->app->make(TenantTableRegistry::class)->flush();
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:P4nBGnpMOgJt0DIfvJ8w0hTL6zVK4d1IfrLkMH9gWEQ=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('cache.default', 'array');
        // The workbench UserFactory hashes a password for every seeded user;
        // default bcrypt rounds dominate the suite's wall clock.
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('tenant-guard.tenant_model', Tenant::class);
        $app['config']->set('tenant-guard.missing_context', 'throw');
        $app['config']->set('tenant-guard.sentinel.mode', 'off');
        $app['config']->set('tenant-guard.cache.enabled', false);
        $app['config']->set('tenant-guard.resolution.central_domains', ['example.test']);
    }

    /**
     * A realistic slice of tenant data: one author, N posts, two comments each.
     *
     * @return \Illuminate\Support\Collection<int, Post>
     */
    protected function seedPosts(Tenant $tenant, int $count = 2)
    {
        return $this->withinTenant($tenant, function () use ($count) {
            $author = User::factory()->create();

            return Post::factory()
                ->count($count)
                ->for($author, 'author')
                ->create()
                ->each(fn (Post $post) => Comment::factory()->count(2)->create(['post_id' => $post->id]));
        });
    }
}
