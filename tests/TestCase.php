<?php

namespace Ifds\TenantGuard\Tests;

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Sql\TenantTableRegistry;
use Ifds\TenantGuard\Testing\InteractsWithTenancy;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Console\Output\BufferedOutput;
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
     * Run an artisan command and return exactly what it printed.
     *
     * `Artisan::output()` reads back through the console kernel's own last
     * buffer, which has proven unreliable across Laravel/Testbench version
     * combinations. The deeper cause: `Command::run()` builds its OutputStyle
     * via `$container->make(OutputStyle::class, ['output' => $output])`, and
     * the container returns a cached *instance* binding as-is - ignoring the
     * override - once one exists. `RefreshDatabase`'s own internal
     * `$this->artisan('migrate', ...)` call leaves exactly such an instance
     * bound (wrapping *its* mocked output), so every command run afterwards
     * silently writes into that stale mock instead of whatever output we
     * explicitly pass in. Clearing the binding first forces a fresh one built
     * from our buffer. Harmless no-op when nothing is bound.
     */
    protected function artisanOutput(string $command, array $parameters = []): string
    {
        $this->app->offsetUnset(OutputStyle::class);

        $output = new BufferedOutput;

        Artisan::call($command, $parameters, $output);

        return $output->fetch();
    }

    /**
     * @return array<mixed>
     */
    protected function artisanJson(string $command, array $parameters = []): array
    {
        return json_decode($this->artisanOutput($command, $parameters), true) ?? [];
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
