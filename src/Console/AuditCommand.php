<?php

namespace Ifds\TenantGuard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Console\Concerns\DiscoversModels;
use Ifds\TenantGuard\Console\Concerns\InspectsSchema;

/**
 * Layer 5 - catches the drift that no runtime guard can see, because the code
 * that would trip the guard has not been written yet.
 *
 * Exits non-zero when it finds anything, so it belongs in CI.
 */
class AuditCommand extends Command
{
    use DiscoversModels;
    use InspectsSchema;

    protected $signature = 'tenant-guard:audit
                            {--connection= : The database connection to inspect}
                            {--json : Emit machine-readable output}
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Report tables and models whose tenant isolation is incomplete';

    /** @var list<array{severity: string, type: string, subject: string, detail: string}> */
    protected array $findings = [];

    public function handle(): int
    {
        // Artisan reuses the command instance, so a second invocation in the
        // same process must not inherit the first run's findings.
        $this->findings = [];

        $connection = DB::connection($this->option('connection') ?: null);
        $tenantKey = (string) config('tenant-guard.tenant_key', 'tenant_id');

        $tables = $this->tableNames($connection);
        $models = $this->discoverModels($this->modelPaths());

        $guardedModels = [];
        $modelTables = [];

        foreach ($models as $model) {
            /** @var Model $instance */
            $instance = new $model;
            $modelTables[$instance->getTable()][] = $model;

            if ($this->usesTenantTrait($model)) {
                $guardedModels[$instance->getTable()][] = $model;
            }
        }

        $ignored = array_merge(
            (array) config('tenant-guard.audit.ignored_tables', []),
            [(string) config('tenant-guard.tenants_table', 'tenants')],
        );

        $schema = $connection->getSchemaBuilder();

        foreach ($tables as $table) {
            if (in_array($table, $ignored, true)) {
                continue;
            }

            $hasColumn = $schema->hasColumn($table, $tenantKey);
            $isGuarded = isset($guardedModels[$table]);

            if ($hasColumn && ! $isGuarded) {
                $this->add(
                    'error',
                    'unguarded-table',
                    $table,
                    isset($modelTables[$table])
                        ? sprintf('has a `%s` column but [%s] does not use the BelongsToTenant trait', $tenantKey, implode(', ', $modelTables[$table]))
                        : sprintf('has a `%s` column but no model uses the BelongsToTenant trait', $tenantKey),
                );
            }

            if (! $hasColumn && $isGuarded) {
                $this->add(
                    'error',
                    'missing-column',
                    $table,
                    sprintf('[%s] is guarded but the table has no `%s` column', implode(', ', $guardedModels[$table]), $tenantKey),
                );
            }

            if (! $hasColumn && ! $isGuarded) {
                $this->add(
                    'warning',
                    'unclassified',
                    $table,
                    'is neither tenant-owned nor on the central allow-list - classify it deliberately',
                );
            }

            if ($hasColumn && $isGuarded && config('tenant-guard.audit.check_indexes', true)) {
                $this->auditIndexes($connection, $table, $tenantKey);
            }
        }

        // A guarded model whose table is not in the schema at all.
        foreach (array_keys($guardedModels) as $table) {
            if (! in_array($table, $tables, true)) {
                $this->add('warning', 'missing-table', $table, 'is guarded by a model but does not exist on this connection');
            }
        }

        return $this->render();
    }

    protected function auditIndexes($connection, string $table, string $tenantKey): void
    {
        $indexes = $this->indexesFor($connection, $table);

        if ($indexes === []) {
            return;
        }

        foreach ($indexes as $index) {
            if (($index['columns'][0] ?? null) === $tenantKey) {
                return;
            }
        }

        $this->add(
            'warning',
            'missing-index',
            $table,
            sprintf('has no index leading with `%s`; every scoped query will scan more rows than it needs', $tenantKey),
        );
    }

    /** @return list<string> */
    protected function modelPaths(): array
    {
        return array_map(
            fn (string $path) => str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path),
            (array) config('tenant-guard.audit.model_paths', ['app/Models']),
        );
    }

    protected function add(string $severity, string $type, string $subject, string $detail): void
    {
        $this->findings[] = compact('severity', 'type', 'subject', 'detail');
    }

    protected function render(): int
    {
        $errors = array_filter($this->findings, fn ($f) => $f['severity'] === 'error');
        $warnings = array_filter($this->findings, fn ($f) => $f['severity'] === 'warning');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'errors' => array_values($errors),
                'warnings' => array_values($warnings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($this->findings === []) {
                $this->info('Tenant Guard audit: every table is accounted for.');

                return self::SUCCESS;
            }

            $this->table(
                ['', 'Type', 'Subject', 'Detail'],
                array_map(fn ($f) => [
                    $f['severity'] === 'error' ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>',
                    $f['type'],
                    $f['subject'],
                    $f['detail'],
                ], $this->findings),
            );

            $this->newLine();
            $this->line(sprintf('%d error(s), %d warning(s).', count($errors), count($warnings)));
        }

        if ($errors !== []) {
            return self::FAILURE;
        }

        return $this->option('strict') && $warnings !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{severity: string, type: string, subject: string, detail: string}> */
    public function findings(): array
    {
        return $this->findings;
    }
}
