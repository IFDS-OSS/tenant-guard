<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Exceptions\CrossTenantWriteException;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Exceptions\UnscopedQueryException;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Sql\SqlSentinel;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The adversarial suite.
 *
 * Every test here is a realistic mistake a developer makes on a Friday
 * afternoon. Each one must fail closed. This is the file to extend whenever
 * someone thinks of a new way to leak.
 */
class LeakHuntTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 3);

        TenantGuard::set($this->acme);
    }

    // T1 - the classic
    public function test_a_bare_all_call_cannot_see_other_tenants(): void
    {
        $this->assertCount(2, Post::all());
    }

    // T2 - guessing an id
    public function test_enumerating_ids_finds_nothing(): void
    {
        $reachable = collect(range(1, 20))->filter(fn (int $id) => Post::find($id) !== null);

        $this->assertSame(2, $reachable->count());
        $this->assertSame(
            [$this->acme->id],
            Post::all()->pluck('tenant_id')->unique()->values()->all()
        );
    }

    // T3 - a model loaded elsewhere, saved here
    public function test_a_model_carried_over_from_another_context_cannot_be_saved(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $this->assertFailsClosed(fn () => $foreign->update(['title' => 'hijacked']));
    }

    // T4 - mass assignment
    public function test_a_request_payload_cannot_choose_its_tenant(): void
    {
        $payload = ['title' => 'X', 'slug' => 'leak-x', 'tenant_id' => $this->globex->id];

        $this->expectException(CrossTenantWriteException::class);

        Post::create($payload);
    }

    // T4b - force fill is not a loophole for the write guard
    public function test_force_fill_still_hits_the_write_guard(): void
    {
        $post = new Post;
        $post->forceFill(['title' => 'X', 'slug' => 'leak-ff', 'tenant_id' => $this->globex->id]);

        $this->expectException(CrossTenantWriteException::class);

        $post->save();
    }

    // T5 - the query builder
    public function test_the_query_builder_is_caught_by_the_sentinel(): void
    {
        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_THROW]);

        $this->expectException(UnscopedQueryException::class);

        DB::table('posts')->get();
    }

    // T6 - raw SQL
    public function test_raw_sql_is_caught_by_the_sentinel(): void
    {
        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_THROW]);

        $this->expectException(UnscopedQueryException::class);

        DB::select('select id, title from posts order by id');
    }

    // T6b - a raw expression smuggled into an Eloquent query
    public function test_a_raw_join_to_an_unscoped_table_is_reported(): void
    {
        $sentinel = $this->app->make(SqlSentinel::class);
        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_LOG]);

        $violations = $sentinel->violationsFor(
            'select * from "posts" where "posts"."tenant_id" = ? '
            .'and "id" in (select "post_id" from "comments")'
        );

        $this->assertSame(['comments'], $violations);
    }

    // T7 - background work with no context
    public function test_work_with_no_context_refuses_rather_than_returning_everything(): void
    {
        TenantGuard::forget();

        $this->assertFailsClosed(fn () => Post::count());
    }

    // T9 - validation as an enumeration oracle
    public function test_validation_cannot_confirm_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $validator = validator(
            ['post_id' => $foreign->id],
            ['post_id' => [new \Ifds\TenantGuard\Rules\TenantOwned(Post::class)]]
        );

        $this->assertFalse($validator->passes());
    }

    // T11 - a null-tenant row can never be created
    public function test_a_row_with_a_null_tenant_can_never_be_created(): void
    {
        TenantGuard::forget();

        $this->expectException(MissingTenantContextException::class);

        Post::create(['title' => 'Ghost', 'slug' => 'ghost']);
    }

    // Relations
    public function test_a_relation_cannot_be_used_to_reach_across_tenants(): void
    {
        $foreignPost = $this->withinTenant($this->globex, fn () => Post::first());

        TenantGuard::set($this->acme);

        $this->assertSame(0, Comment::where('post_id', $foreignPost->id)->count());
        $this->assertNull(Post::find($foreignPost->id));
    }

    // whereHas / subqueries
    public function test_where_has_stays_inside_the_tenant(): void
    {
        $this->assertSame(2, Post::whereHas('comments')->count());
    }

    // firstOrCreate / updateOrCreate
    public function test_first_or_create_stamps_the_current_tenant(): void
    {
        $post = Post::firstOrCreate(['slug' => 'brand-new'], ['title' => 'Brand new']);

        $this->assertSame($this->acme->id, $post->tenant_id);
    }

    public function test_update_or_create_cannot_hijack_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create([
            'title' => 'Theirs',
            'slug' => 'shared-slug',
        ]));

        TenantGuard::set($this->acme);

        // Same slug, different tenant: this must create a new row, not adopt theirs.
        $mine = Post::updateOrCreate(['slug' => 'shared-slug'], ['title' => 'Mine']);

        $this->assertNotSame($foreign->id, $mine->id);
        $this->assertSame($this->acme->id, $mine->tenant_id);
        $this->assertSame('Theirs', $foreign->fresh()->title, 'their row is untouched');
    }

    // Mass update / delete through Eloquent
    public function test_a_mass_update_only_touches_the_current_tenant(): void
    {
        Post::query()->update(['title' => 'Bulk edited']);

        $this->assertSame(2, Post::where('title', 'Bulk edited')->count());
        $this->assertSame(
            0,
            TenantGuard::runFor($this->globex, fn () => Post::where('title', 'Bulk edited')->count())
        );
    }

    public function test_a_mass_delete_only_touches_the_current_tenant(): void
    {
        Post::query()->delete();

        $this->assertSame(0, Post::count());
        $this->assertSame(3, TenantGuard::runFor($this->globex, fn () => Post::count()));
    }

    // Aggregates and pluck
    public function test_aggregates_and_pluck_are_scoped(): void
    {
        $this->assertSame(2, Post::count());
        $this->assertCount(2, Post::pluck('id'));
        $this->assertCount(2, Post::query()->select('id')->get());
    }

    // Users
    public function test_a_user_lookup_by_email_cannot_cross_tenants(): void
    {
        $theirs = $this->withinTenant($this->globex, fn () => User::first());

        TenantGuard::set($this->acme);

        $this->assertNull(User::where('email', $theirs->email)->first());
    }

    // The whole request path, with the sentinel armed
    public function test_a_full_request_produces_no_unscoped_queries(): void
    {
        $this->assertNoUnscopedQueries(function () {
            Post::with('comments')->get();
            Post::count();
            User::all();
            Comment::query()->latest()->take(5)->get();
        });
    }

    // And the negative control - prove the detector is not simply always green
    public function test_the_detector_itself_works(): void
    {
        $this->assertUnscopedQueryDetected(fn () => DB::table('posts')->get());
    }

    // Isolation helper end to end
    public function test_isolation_helper_covers_every_guarded_model(): void
    {
        $this->assertIsolatedBetween(
            Post::class,
            $this->acme,
            $this->globex,
            fn () => Post::create(['title' => 'Isolated', 'slug' => 'isolated'])
        );

        $this->assertIsolatedBetween(
            User::class,
            $this->globex,
            $this->acme,
            fn () => User::factory()->create()
        );
    }
}
