<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Exceptions\UnscopedQueryException;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Sql\SqlSentinel;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Post;

/**
 * Layer 3 - the query-level net that catches everything Eloquent never sees.
 */
class SqlSentinelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 2);

        TenantGuard::set($this->acme);
    }

    protected function arm(string $mode = SqlSentinel::MODE_THROW): SqlSentinel
    {
        config(['tenant-guard.sentinel.mode' => $mode]);

        return $this->app->make(SqlSentinel::class);
    }

    public function test_it_blocks_an_unscoped_query_builder_call(): void
    {
        $this->arm();

        $this->expectException(UnscopedQueryException::class);

        DB::table('posts')->get();
    }

    public function test_it_blocks_unscoped_raw_sql(): void
    {
        $this->arm();

        $this->expectException(UnscopedQueryException::class);

        DB::select('select * from posts');
    }

    public function test_it_allows_a_scoped_query_builder_call(): void
    {
        $this->arm();

        $rows = DB::table('posts')->where('tenant_id', $this->acme->id)->get();

        $this->assertCount(2, $rows);
    }

    public function test_it_allows_eloquent_because_eloquent_scopes_itself(): void
    {
        $this->arm();

        $this->assertSame(2, Post::count());
    }

    public function test_it_allows_central_tables(): void
    {
        $this->arm();

        $this->assertIsInt(DB::table('plans')->count());
        $this->assertIsInt(DB::table('tenants')->count());
    }

    public function test_it_blocks_an_unscoped_write(): void
    {
        $this->arm();

        $this->expectException(UnscopedQueryException::class);

        DB::table('posts')->update(['title' => 'rewritten']);
    }

    public function test_it_blocks_an_unscoped_delete(): void
    {
        $this->arm();

        $this->expectException(UnscopedQueryException::class);

        DB::table('posts')->delete();
    }

    public function test_the_blocked_query_never_reaches_the_database(): void
    {
        $this->arm();

        try {
            DB::table('posts')->delete();
        } catch (UnscopedQueryException) {
            // expected
        }

        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_OFF]);

        $this->assertSame(4, (int) DB::table('posts')->count(), 'the delete must not have run');
    }

    public function test_run_without_is_an_accepted_bypass(): void
    {
        $this->arm();

        $rows = TenantGuard::runWithout(fn () => DB::table('posts')->get());

        $this->assertCount(4, $rows);
    }

    public function test_log_mode_reports_without_blocking(): void
    {
        $sentinel = $this->arm(SqlSentinel::MODE_LOG);
        $sentinel->record();

        $rows = DB::table('posts')->get();

        $this->assertCount(4, $rows, 'log mode must not block');
        $this->assertNotEmpty($sentinel->recorded());
        $this->assertSame(['posts'], $sentinel->recorded()[0]['tables']);

        $sentinel->stopRecording();
    }

    public function test_off_mode_does_nothing(): void
    {
        $this->arm(SqlSentinel::MODE_OFF);

        $this->assertCount(4, DB::table('posts')->get());
    }

    public function test_it_flags_a_join_where_only_one_side_is_scoped(): void
    {
        $sentinel = $this->arm(SqlSentinel::MODE_LOG);

        $violations = $sentinel->violationsFor(
            'select * from "posts" inner join "comments" on "comments"."post_id" = "posts"."id" '
            .'where "posts"."tenant_id" = ?'
        );

        $this->assertSame(['comments'], $violations, 'the unscoped side of the join must be reported');
    }

    public function test_it_accepts_a_join_where_both_sides_are_scoped(): void
    {
        $sentinel = $this->arm(SqlSentinel::MODE_LOG);

        $violations = $sentinel->violationsFor(
            'select * from "posts" inner join "comments" on "comments"."post_id" = "posts"."id" '
            .'where "posts"."tenant_id" = ? and "comments"."tenant_id" = ?'
        );

        $this->assertSame([], $violations);
    }

    public function test_ignore_patterns_are_honoured(): void
    {
        $sentinel = $this->arm(SqlSentinel::MODE_LOG);

        $this->assertSame([], $sentinel->violationsFor('pragma foreign_keys = on'));
        $this->assertSame([], $sentinel->violationsFor('select * from "unknown_table"'));
    }

    public function test_the_protected_table_list_comes_from_booted_models(): void
    {
        $sentinel = $this->arm(SqlSentinel::MODE_LOG);

        $this->assertContains('posts', $sentinel->protectedTables());
        $this->assertContains('comments', $sentinel->protectedTables());
        $this->assertNotContains('plans', $sentinel->protectedTables());
    }

    public function test_config_can_protect_a_table_no_model_has_booted(): void
    {
        config(['tenant-guard.sentinel.tenant_tables' => ['legacy_notes']]);

        $this->arm();

        $this->expectException(UnscopedQueryException::class);

        DB::table('legacy_notes')->get();
    }

    public function test_the_exception_names_the_offending_tables(): void
    {
        $this->arm();

        try {
            DB::table('posts')->get();
            $this->fail('expected the sentinel to block the query');
        } catch (UnscopedQueryException $e) {
            $this->assertSame(['posts'], $e->tables);
            $this->assertStringContainsString('tenant_id', $e->getMessage());
        }
    }
}
