<?php

namespace Ifds\TenantGuard\Console\Concerns;

use Illuminate\Database\Connection;

/**
 * Schema introspection that works across Laravel 10, 11 and 12, where the
 * schema builder API changed shape more than once.
 */
trait InspectsSchema
{
    /** @return list<string> */
    protected function tableNames(Connection $connection): array
    {
        $builder = $connection->getSchemaBuilder();

        if (method_exists($builder, 'getTableListing')) {
            return array_map(
                fn (string $table) => $this->stripSchemaPrefix($table),
                $builder->getTableListing()
            );
        }

        if (method_exists($builder, 'getTables')) {
            return array_map(
                fn (array $table) => $this->stripSchemaPrefix($table['name'] ?? ''),
                $builder->getTables()
            );
        }

        return $this->tableNamesViaDriver($connection);
    }

    /** @return list<string> */
    protected function tableNamesViaDriver(Connection $connection): array
    {
        $rows = match ($connection->getDriverName()) {
            'sqlite' => $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"),
            'mysql', 'mariadb' => $connection->select('show tables'),
            'pgsql' => $connection->select("select tablename as name from pg_catalog.pg_tables where schemaname not in ('pg_catalog', 'information_schema')"),
            'sqlsrv' => $connection->select('select table_name as name from information_schema.tables where table_type = ?', ['BASE TABLE']),
            default => [],
        };

        $names = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $name = $values['name'] ?? (($first = reset($values)) === false ? '' : $first);

            if ($name !== '') {
                $names[] = $this->stripSchemaPrefix((string) $name);
            }
        }

        return $names;
    }

    /**
     * @return list<array{name: string, columns: list<string>}>
     */
    protected function indexesFor(Connection $connection, string $table): array
    {
        $builder = $connection->getSchemaBuilder();

        if (! method_exists($builder, 'getIndexes')) {
            return [];
        }

        return array_map(fn (array $index) => [
            'name' => (string) ($index['name'] ?? ''),
            'columns' => array_values((array) ($index['columns'] ?? [])),
        ], $builder->getIndexes($table));
    }

    protected function stripSchemaPrefix(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
