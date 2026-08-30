<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Post;

/**
 * The fail-closed matrix. This is the behaviour that decides whether a
 * forgotten middleware is a 500 or a data breach.
 */
class MissingContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 3);

        $this->actingWithoutTenant();
    }

    public function test_throw_mode_refuses_to_read(): void
    {
        config(['tenant-guard.missing_context' => 'throw']);

        $this->expectException(MissingTenantContextException::class);

        Post::count();
    }

    public function test_throw_mode_message_explains_the_way_out(): void
    {
        config(['tenant-guard.missing_context' => 'throw']);

        try {
            Post::all();
            $this->fail('expected a refusal');
        } catch (MissingTenantContextException $e) {
            $this->assertStringContainsString('runWithout', $e->getMessage());
            $this->assertStringContainsString(Post::class, $e->getMessage());
        }
    }

    public function test_empty_mode_returns_nothing(): void
    {
        config(['tenant-guard.missing_context' => 'empty']);

        $this->assertSame(0, Post::count());
        $this->assertCount(0, Post::all());
    }

    public function test_ignore_mode_returns_everything(): void
    {
        config(['tenant-guard.missing_context' => 'ignore']);

        $this->assertSame(5, Post::count());
    }

    public function test_writes_never_honour_ignore_mode(): void
    {
        config(['tenant-guard.missing_context' => 'ignore']);

        $this->expectException(MissingTenantContextException::class);

        Post::create(['title' => 'Orphan', 'slug' => 'orphan']);
    }

    public function test_writes_never_honour_empty_mode(): void
    {
        config(['tenant-guard.missing_context' => 'empty']);

        $this->expectException(MissingTenantContextException::class);

        Post::create(['title' => 'Orphan', 'slug' => 'orphan-2']);
    }

    public function test_building_a_query_is_always_safe(): void
    {
        config(['tenant-guard.missing_context' => 'throw']);

        // Global scopes apply on execution, not construction, so this is fine.
        $builder = Post::query()->where('title', 'anything');

        $this->assertNotNull($builder);

        $this->expectException(MissingTenantContextException::class);

        $builder->get();
    }

    public function test_run_without_works_in_every_mode(): void
    {
        foreach (['throw', 'empty', 'ignore'] as $mode) {
            config(['tenant-guard.missing_context' => $mode]);

            $this->assertSame(
                5,
                $this->withoutTenancy(fn () => Post::count()),
                "runWithout should bypass in [{$mode}] mode"
            );
        }
    }
}
