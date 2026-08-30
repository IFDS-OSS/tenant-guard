<?php

namespace Ifds\TenantGuard\Exceptions;

/**
 * The SQL Sentinel refused a query that touches tenant-owned tables without a
 * tenant predicate.
 */
class UnscopedQueryException extends TenantGuardException
{
    /** @var list<string> */
    public array $tables = [];

    public string $sql = '';

    /**
     * @param  list<string>  $tables
     */
    public static function make(string $sql, array $tables): self
    {
        $exception = new self(sprintf(
            'Blocked an unscoped query against tenant-owned table%s [%s]. Add a %s predicate, or '
            .'wrap the call in TenantGuard::runWithout() if it is intentional. SQL: %s',
            count($tables) === 1 ? '' : 's',
            implode(', ', $tables),
            config('tenant-guard.tenant_key', 'tenant_id'),
            $sql,
        ));

        $exception->tables = $tables;
        $exception->sql = $sql;

        return $exception;
    }
}
