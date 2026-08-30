<?php

namespace Ifds\TenantGuard\Tests\Unit;

use Ifds\TenantGuard\Sql\SqlAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests - no framework, no database.
 */
class SqlAnalyzerTest extends TestCase
{
    private SqlAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new SqlAnalyzer;
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('statements')]
    public function test_it_extracts_table_names(string $sql, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, $this->analyzer->tableNames($sql));
    }

    public static function statements(): array
    {
        return [
            'simple select' => ['select * from "posts"', ['posts']],
            'mysql quoting' => ['select * from `posts`', ['posts']],
            'sqlsrv quoting' => ['select * from [posts]', ['posts']],
            'unquoted' => ['select * from posts', ['posts']],
            'schema qualified' => ['select * from "app"."posts"', ['posts']],
            'with alias' => ['select * from "posts" as "p"', ['posts']],
            'implicit alias' => ['select * from "posts" "p"', ['posts']],
            'inner join' => [
                'select * from "posts" inner join "comments" on "comments"."post_id" = "posts"."id"',
                ['posts', 'comments'],
            ],
            'left join with aliases' => [
                'select * from "posts" as "p" left join "users" as "u" on "u"."id" = "p"."user_id"',
                ['posts', 'users'],
            ],
            'insert' => ['insert into "posts" ("title") values (?)', ['posts']],
            'update' => ['update "posts" set "title" = ? where "id" = ?', ['posts']],
            'delete' => ['delete from "posts" where "id" = ?', ['posts']],
            'subquery' => [
                'select * from "posts" where "id" in (select "post_id" from "comments")',
                ['posts', 'comments'],
            ],
            'derived table is skipped' => [
                'select * from (select 1) as "x" join "posts" on 1 = 1',
                ['posts'],
            ],
            'string literal is not a table' => [
                'select * from "posts" where "title" = \'from secrets\'',
                ['posts'],
            ],
        ];
    }

    public function test_it_does_not_mistake_keywords_for_aliases(): void
    {
        $tables = $this->analyzer->tables('select * from "posts" where "id" = ?');

        $this->assertSame([['table' => 'posts', 'alias' => null]], $tables);
    }

    public function test_it_captures_aliases(): void
    {
        $tables = $this->analyzer->tables('select * from "posts" as "p" where "p"."id" = ?');

        $this->assertSame([['table' => 'posts', 'alias' => 'p']], $tables);
    }

    public function test_it_detects_a_qualified_tenant_predicate(): void
    {
        $sql = 'select * from "posts" where "posts"."tenant_id" = ?';

        $this->assertTrue($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id'));
    }

    public function test_it_detects_an_alias_qualified_predicate(): void
    {
        $sql = 'select * from "posts" as "p" where "p"."tenant_id" = ?';

        $this->assertFalse($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id'));
        $this->assertTrue($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id', ['p']));
    }

    public function test_an_unqualified_predicate_counts_only_when_allowed(): void
    {
        $sql = 'select * from "posts" where "tenant_id" = ?';

        $this->assertFalse($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id'));
        $this->assertTrue($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id', [], true));
    }

    public function test_it_reports_a_missing_predicate(): void
    {
        $sql = 'select * from "posts" where "published_at" is not null';

        $this->assertFalse($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id', [], true));
    }

    public function test_a_tenant_id_inside_a_string_literal_does_not_count(): void
    {
        $sql = 'select * from "posts" where "title" = \'tenant_id = 1\'';

        $this->assertFalse($this->analyzer->hasTenantPredicate($sql, 'posts', 'tenant_id', [], true));
    }

    public function test_it_recognises_writes(): void
    {
        $this->assertTrue($this->analyzer->isWrite('insert into "posts" ("a") values (?)'));
        $this->assertTrue($this->analyzer->isWrite('  UPDATE "posts" set "a" = ?'));
        $this->assertTrue($this->analyzer->isWrite('delete from "posts"'));
        $this->assertFalse($this->analyzer->isWrite('select * from "posts"'));
    }

    public function test_results_are_memoised(): void
    {
        $sql = 'select * from "posts"';

        $this->assertSame($this->analyzer->tables($sql), $this->analyzer->tables($sql));
    }
}
