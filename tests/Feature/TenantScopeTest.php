<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Scopes\TenantScope;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Plan;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Layer 1 - the Eloquent global scope.
 */
class TenantScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 3);
        $this->seedPosts($this->globex, 5);
    }

    public function test_queries_only_see_the_current_tenant(): void
    {
        TenantGuard::set($this->acme);
        $this->assertSame(3, Post::count());

        TenantGuard::set($this->globex);
        $this->assertSame(5, Post::count());
    }

    public function test_all_returns_only_the_current_tenants_rows(): void
    {
        TenantGuard::set($this->acme);

        $this->assertCount(3, Post::all());
        $this->assertTrue(Post::all()->every(fn (Post $p) => $p->tenant_id === $this->acme->id));
    }

    public function test_find_cannot_reach_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $this->assertNull(Post::find($foreign->id));
    }

    public function test_find_or_fail_throws_for_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Post::findOrFail($foreign->id);
    }

    public function test_the_scope_survives_where_clauses_and_ordering(): void
    {
        TenantGuard::set($this->acme);

        $sql = Post::query()->where('title', '!=', '')->orderBy('id')->toSql();

        $this->assertStringContainsString('"posts"."tenant_id" =', $sql);
    }

    public function test_relations_are_scoped_too(): void
    {
        TenantGuard::set($this->acme);

        $post = Post::first();

        $this->assertCount(2, $post->comments);
        $this->assertStringContainsString('"comments"."tenant_id" =', $post->comments()->toSql());
    }

    public function test_eager_loading_is_scoped(): void
    {
        TenantGuard::set($this->acme);

        $posts = Post::with('comments')->get();

        $this->assertSame(6, $posts->sum(fn (Post $p) => $p->comments->count()));
        $this->assertSame(
            [$this->acme->id],
            $posts->flatMap->comments->pluck('tenant_id')->unique()->values()->all()
        );
    }

    public function test_aggregate_queries_are_scoped(): void
    {
        TenantGuard::set($this->globex);

        $this->assertSame(5, Post::count());
        $this->assertSame(5, (int) Post::query()->selectRaw('count(*) as c')->value('c'));
        $this->assertTrue(Post::exists());
    }

    public function test_central_models_are_untouched(): void
    {
        Plan::factory()->count(2)->create();

        TenantGuard::set($this->acme);
        $this->assertSame(2, Plan::count());

        TenantGuard::set($this->globex);
        $this->assertSame(2, Plan::count());

        $this->assertNotTenantScoped(Plan::class);
        $this->assertTenantScoped(Post::class);
    }

    public function test_without_tenant_scope_sees_everything(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame(8, Post::withoutTenantScope()->count());
        $this->assertSame(8, Post::allTenants()->count());
    }

    public function test_for_tenant_reaches_another_tenant_explicitly(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame(5, Post::forTenant($this->globex)->count());
        $this->assertSame(5, Post::forTenant($this->globex->id)->count());
    }

    public function test_run_without_disables_the_scope(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame(8, TenantGuard::runWithout(fn () => Post::count()));
        $this->assertSame(3, Post::count(), 'the scope comes back afterwards');
    }

    public function test_soft_deletes_compose_with_the_tenant_scope(): void
    {
        TenantGuard::set($this->acme);

        Post::first()->delete();

        $this->assertSame(2, Post::count());
        $this->assertSame(3, Post::withTrashed()->count());
        $this->assertSame(1, Post::onlyTrashed()->count());

        // ...and trashed rows still do not leak.
        TenantGuard::set($this->globex);
        $this->assertSame(5, Post::withTrashed()->count());
    }

    public function test_the_scope_uses_a_qualified_column_so_joins_stay_unambiguous(): void
    {
        TenantGuard::set($this->acme);

        $count = Post::query()
            ->join('comments', 'comments.post_id', '=', 'posts.id')
            ->count();

        $this->assertSame(6, $count);
    }

    public function test_pagination_is_scoped(): void
    {
        TenantGuard::set($this->globex);

        $page = Post::query()->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_users_are_scoped_as_well(): void
    {
        TenantGuard::set($this->acme);
        $this->assertSame(1, User::count());

        TenantGuard::set($this->globex);
        $this->assertSame(1, User::count());

        $this->assertSame(2, $this->countAllTenants(User::class));
    }

    public function test_the_raw_table_still_holds_every_row(): void
    {
        // Proof that isolation is a query-time property, not a storage one.
        $this->assertSame(8, (int) DB::table('posts')->count());
    }

    public function test_the_scope_can_be_removed_by_identifier(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame(8, Post::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_comments_relation_across_tenants_returns_nothing(): void
    {
        $foreignPost = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $this->assertSame(0, Comment::where('post_id', $foreignPost->id)->count());
    }
}
