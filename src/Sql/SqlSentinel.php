<?php

namespace Ifds\TenantGuard\Sql;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Log\LogManager;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Events\CrossTenantAccessDenied;
use Ifds\TenantGuard\Exceptions\UnscopedQueryException;

/**
 * Layer 3 - the last line of defence.
 *
 * Hooks Connection::beforeExecuting(), so it sees every statement the
 * application runs, including DB::table(), DB::select() and anything else that
 * never touches Eloquent - and sees it *before* the database does, which means
 * a violation can be blocked rather than merely reported.
 */
class SqlSentinel
{
    public const MODE_OFF = 'off';

    public const MODE_LOG = 'log';

    public const MODE_THROW = 'throw';

    /** @var array<string, list<string>> collected violations, keyed by sql */
    protected array $violations = [];

    protected bool $recording = false;

    /** @var \SplObjectStorage<Connection, true>|null */
    protected ?\SplObjectStorage $attached = null;

    public function __construct(
        protected SqlAnalyzer $analyzer,
        protected TenantTableRegistry $registry,
        protected TenantContext $tenancy,
        protected Config $config,
        protected Dispatcher $events,
        protected ?LogManager $log = null,
    ) {
        $this->attached = new \SplObjectStorage;
    }

    /**
     * Start watching a connection. Safe to call repeatedly.
     */
    public function watch(Connection $connection): void
    {
        if ($this->attached->contains($connection)) {
            return;
        }

        $this->attached->attach($connection);

        $connection->beforeExecuting(function ($query, $bindings, $connection) {
            $this->inspect((string) $query);
        });
    }

    public function inspect(string $sql): void
    {
        if ($this->mode() === self::MODE_OFF || $this->tenancy->isBypassed()) {
            return;
        }

        if ($this->isIgnored($sql)) {
            return;
        }

        $offending = $this->violationsFor($sql);

        if ($offending === []) {
            return;
        }

        $this->report($sql, $offending);
    }

    /**
     * Which tenant-owned tables in this statement have no tenant predicate.
     *
     * @return list<string>
     */
    public function violationsFor(string $sql): array
    {
        $references = $this->analyzer->tables($sql);

        if ($references === []) {
            return [];
        }

        $protected = $this->protectedTables();
        $central = $this->centralTables();
        $column = (string) $this->config->get('tenant-guard.tenant_key', 'tenant_id');

        // Group aliases per table, so `posts as p ... p.tenant_id` still counts.
        $aliases = [];
        $tables = [];

        foreach ($references as $reference) {
            $table = $reference['table'];
            $tables[$table] = true;

            if ($reference['alias'] !== null) {
                $aliases[$table][] = $reference['alias'];
            }
        }

        $tenantTables = array_values(array_filter(
            array_keys($tables),
            fn (string $table) => in_array($table, $protected, true) && ! in_array($table, $central, true)
        ));

        if ($tenantTables === []) {
            return [];
        }

        // With a single tenant table in play, a bare `tenant_id = ?` is
        // unambiguous, so DB::table('posts')->where('tenant_id', 1) passes.
        $allowUnqualified = count($tenantTables) === 1;

        $offending = [];

        foreach ($tenantTables as $table) {
            $ok = $this->analyzer->hasTenantPredicate(
                $sql,
                $table,
                $column,
                $aliases[$table] ?? [],
                $allowUnqualified,
            );

            if (! $ok) {
                $offending[] = $table;
            }
        }

        return $offending;
    }

    /**
     * @param  list<string>  $tables
     */
    protected function report(string $sql, array $tables): void
    {
        if ($this->recording) {
            $this->violations[] = ['sql' => $sql, 'tables' => $tables];
        }

        $this->events->dispatch(new CrossTenantAccessDenied(
            layer: 'sql-sentinel',
            subject: implode(', ', $tables),
            currentTenant: $this->tenancy->id(),
            reason: 'query without a tenant predicate',
        ));

        if ($this->mode() === self::MODE_THROW) {
            throw UnscopedQueryException::make($sql, $tables);
        }

        $this->logger()?->warning('[tenant-guard] Unscoped query on tenant-owned table.', [
            'tables' => $tables,
            'tenant' => $this->tenancy->id(),
            'sql' => $sql,
        ]);
    }

    /** @return list<string> */
    public function protectedTables(): array
    {
        $configured = (array) $this->config->get('tenant-guard.sentinel.tenant_tables', []);

        return array_values(array_unique(array_merge($configured, $this->registry->tables())));
    }

    /** @return list<string> */
    protected function centralTables(): array
    {
        return array_values(array_unique(array_merge(
            (array) $this->config->get('tenant-guard.sentinel.central_tables', []),
            [(string) $this->config->get('tenant-guard.tenants_table', 'tenants')],
        )));
    }

    protected function isIgnored(string $sql): bool
    {
        foreach ((array) $this->config->get('tenant-guard.sentinel.ignore_patterns', []) as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    public function mode(): string
    {
        return (string) $this->config->get('tenant-guard.sentinel.mode', self::MODE_OFF);
    }

    protected function logger()
    {
        return $this->log?->channel($this->config->get('tenant-guard.sentinel.log_channel'));
    }

    // ------------------------------------------------------------------
    // Test-support: collect violations instead of only reacting to them
    // ------------------------------------------------------------------

    public function record(): void
    {
        $this->recording = true;
        $this->violations = [];
    }

    /** @return list<array{sql: string, tables: list<string>}> */
    public function recorded(): array
    {
        return $this->violations;
    }

    public function stopRecording(): void
    {
        $this->recording = false;
        $this->violations = [];
    }
}
