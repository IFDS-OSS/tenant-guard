<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Illuminate\Support\Facades\Cache;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Jobs\CountPostsForCurrentTenant;
use Workbench\App\Models\Post;

/**
 * Layer 4 - the tenant has to survive the trip through the queue, and must not
 * survive one job into the next.
 */
class QueuePropagationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 4);
    }

    protected function workNextJob(): void
    {
        $this->app->make('queue.worker')->runNextJob(
            'database',
            'default',
            new WorkerOptions(maxTries: 1)
        );
    }

    public function test_the_payload_carries_the_tenant(): void
    {
        TenantGuard::set($this->acme);

        CountPostsForCurrentTenant::dispatch();

        $payload = json_decode($this->app['db']->table('jobs')->value('payload'), true);

        $this->assertSame($this->acme->id, $payload['tenant_guard_tenant']);
    }

    public function test_the_worker_restores_the_tenant(): void
    {
        // Note the block body: Job::dispatch() returns a PendingDispatch that
        // only pushes when it is destroyed, so an arrow function would leak the
        // dispatch out of the tenant context. See the README's "Gotchas".
        TenantGuard::runFor($this->globex, function () {
            CountPostsForCurrentTenant::dispatch('globex-result');
        });

        TenantGuard::forget();

        $this->workNextJob();

        $this->assertSame(
            ['tenant' => $this->globex->id, 'posts' => 4],
            Cache::get('globex-result')
        );
    }

    public function test_two_jobs_from_different_tenants_do_not_bleed(): void
    {
        TenantGuard::runFor($this->acme, function () {
            CountPostsForCurrentTenant::dispatch('a');
        });

        TenantGuard::runFor($this->globex, function () {
            CountPostsForCurrentTenant::dispatch('b');
        });

        TenantGuard::forget();

        $this->workNextJob();
        $this->workNextJob();

        $this->assertSame(['tenant' => $this->acme->id, 'posts' => 2], Cache::get('a'));
        $this->assertSame(['tenant' => $this->globex->id, 'posts' => 4], Cache::get('b'));
    }

    public function test_the_context_is_cleared_after_the_job(): void
    {
        TenantGuard::runFor($this->acme, function () {
            CountPostsForCurrentTenant::dispatch('c');
        });

        $this->workNextJob();

        $this->assertFalse(TenantGuard::check(), 'a worker must not keep the tenant between jobs');
    }

    public function test_a_job_dispatched_with_no_tenant_stays_tenantless(): void
    {
        TenantGuard::forget();

        // The job body queries a tenant-owned model, so with no context it must
        // fail closed rather than count every tenant's rows.
        CountPostsForCurrentTenant::dispatch('d');

        $payload = json_decode($this->app['db']->table('jobs')->value('payload'), true);

        $this->assertArrayNotHasKey('tenant_guard_tenant', $payload);

        $failures = [];
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use (&$failures) {
            $failures[] = $event->exception;
        });

        $this->workNextJob();

        $this->assertNull(Cache::get('d'), 'the job must not have produced a cross-tenant count');
        $this->assertCount(1, $failures);
        $this->assertInstanceOf(MissingTenantContextException::class, $failures[0]);
    }

    public function test_propagation_can_be_switched_off(): void
    {
        config(['tenant-guard.queue.propagate' => false]);

        // The payload hook is registered at boot, so re-register with the new
        // setting to model an application that opted out.
        TenantGuard::set($this->acme);

        $this->assertFalse($this->app->make(\Ifds\TenantGuard\Queue\TenantQueueBridge::class)->enabled());
    }

    public function test_posts_counted_in_the_job_match_the_scoped_count(): void
    {
        TenantGuard::runFor($this->acme, function () {
            CountPostsForCurrentTenant::dispatch('e');
        });

        $this->workNextJob();

        $expected = TenantGuard::runFor($this->acme, fn () => Post::count());

        $this->assertSame($expected, Cache::get('e')['posts']);
    }
}
