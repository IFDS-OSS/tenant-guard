<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Ifds\TenantGuard\Events\CrossTenantAccessDenied;
use Ifds\TenantGuard\Exceptions\CrossTenantWriteException;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\Post;

/**
 * Layer 2 - the write guard.
 */
class WriteGuardTest extends TestCase
{
    public function test_it_fills_the_tenant_id_on_create(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

        $this->assertSame($this->acme->id, $post->tenant_id);
    }

    public function test_it_refuses_to_create_without_a_context(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Post::create(['title' => 'Orphan', 'slug' => 'orphan']);
    }

    public function test_it_refuses_a_mass_assigned_foreign_tenant_id(): void
    {
        TenantGuard::set($this->acme);

        $this->expectException(CrossTenantWriteException::class);

        // The model is $guarded = [], so tenant_id *is* mass assignable. The
        // guard rejects it anyway - that is the point.
        Post::create([
            'title' => 'Smuggled',
            'slug' => 'smuggled',
            'tenant_id' => $this->globex->id,
        ]);
    }

    public function test_it_allows_an_explicit_matching_tenant_id(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create([
            'title' => 'Fine',
            'slug' => 'fine',
            'tenant_id' => $this->acme->id,
        ]);

        $this->assertSame($this->acme->id, $post->tenant_id);
    }

    public function test_it_refuses_to_update_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'Theirs', 'slug' => 'theirs']));

        TenantGuard::set($this->acme);

        $foreign->title = 'Mine now';

        $this->expectException(CrossTenantWriteException::class);

        $foreign->save();
    }

    public function test_it_refuses_to_delete_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'Theirs', 'slug' => 'theirs-2']));

        TenantGuard::set($this->acme);

        $this->expectException(CrossTenantWriteException::class);

        $foreign->delete();
    }

    public function test_the_tenant_key_is_immutable(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create(['title' => 'Stay put', 'slug' => 'stay-put']);
        $post->tenant_id = $this->globex->id;

        $this->expectException(CrossTenantWriteException::class);

        $post->save();
    }

    public function test_it_refuses_to_write_without_a_context_even_for_an_owned_row(): void
    {
        $post = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'a']));

        TenantGuard::forget();

        $post->title = 'B';

        $this->expectException(MissingTenantContextException::class);

        $post->save();
    }

    public function test_run_without_allows_deliberate_cross_tenant_writes(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'Theirs', 'slug' => 'theirs-3']));

        TenantGuard::set($this->acme);

        TenantGuard::runWithout(function () use ($foreign) {
            $foreign->title = 'Fixed by an operator';
            $foreign->save();
        });

        $this->assertSame('Fixed by an operator', $foreign->fresh()->title);
    }

    public function test_run_without_still_requires_a_tenant_id_on_create(): void
    {
        TenantGuard::forget();

        $this->expectException(MissingTenantContextException::class);

        TenantGuard::runWithout(fn () => Post::create(['title' => 'Nowhere', 'slug' => 'nowhere']));
    }

    public function test_run_without_accepts_an_explicit_tenant_id_on_create(): void
    {
        TenantGuard::forget();

        $post = TenantGuard::runWithout(fn () => Post::create([
            'title' => 'Seeded',
            'slug' => 'seeded',
            'tenant_id' => $this->globex->id,
        ]));

        $this->assertSame($this->globex->id, $post->tenant_id);
    }

    public function test_soft_delete_and_restore_respect_the_guard(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create(['title' => 'Bin me', 'slug' => 'bin-me']);
        $post->delete();

        $this->assertSoftDeleted($post);

        $post->restore();

        $this->assertSame(1, Post::count());
    }

    public function test_it_emits_an_event_when_it_refuses(): void
    {
        Event::fake([CrossTenantAccessDenied::class]);

        TenantGuard::set($this->acme);

        try {
            Post::create(['title' => 'X', 'slug' => 'x', 'tenant_id' => $this->globex->id]);
        } catch (CrossTenantWriteException) {
            // expected
        }

        Event::assertDispatched(
            CrossTenantAccessDenied::class,
            fn (CrossTenantAccessDenied $e) => $e->layer === 'write-guard'
        );
    }

    public function test_belongs_to_current_tenant_reports_ownership(): void
    {
        $mine = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'M', 'slug' => 'm']));
        $theirs = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'T', 'slug' => 't']));

        TenantGuard::set($this->acme);

        $this->assertTrue($mine->belongsToCurrentTenant());
        $this->assertFalse($theirs->belongsToCurrentTenant());
    }

    public function test_the_tenant_relation_resolves(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create(['title' => 'Rel', 'slug' => 'rel']);

        $this->assertSame($this->acme->id, $post->tenant->getKey());
    }

    public function test_updating_an_owned_row_is_untouched(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::create(['title' => 'Before', 'slug' => 'before']);
        $post->update(['title' => 'After']);

        $this->assertSame('After', $post->fresh()->title);
    }
}
