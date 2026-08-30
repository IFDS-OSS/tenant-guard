<?php

namespace Ifds\TenantGuard\Sql;

/**
 * Pulls table references out of raw SQL and decides whether a tenant predicate
 * is present for each one.
 *
 * This is deliberately a lexical heuristic, not a parser. It backs up the
 * Eloquent layers rather than replacing them, which is why the sentinel it
 * feeds has a "log" mode and an allow-list.
 */
class SqlAnalyzer
{
    /**
     * Words that can follow a table reference but are never an alias.
     */
    private const NOT_ALIASES = [
        'where', 'on', 'set', 'values', 'inner', 'left', 'right', 'full', 'cross',
        'join', 'group', 'order', 'limit', 'offset', 'having', 'union', 'select',
        'from', 'using', 'and', 'or', 'as', 'natural', 'straight_join', 'for',
        'lock', 'into', 'returning', 'default', 'window', 'with', 'when',
    ];

    /** @var array<string, list<array{table: string, alias: ?string}>> */
    protected array $memo = [];

    /**
     * Every table the statement reads from or writes to, with its alias.
     *
     * @return list<array{table: string, alias: ?string}>
     */
    public function tables(string $sql): array
    {
        $key = md5($sql);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $normalised = $this->stripLiterals($sql);

        $pattern = '/\b(?:from|join|update|insert\s+into|into)\s+'
            .'(?!\()'                                  // not a derived table
            .'((?:[`"\[]?[\w$]+[`"\]]?\.)*[`"\[]?[\w$]+[`"\]]?)'
            .'(?:\s+(?:as\s+)?([`"\[]?[\w$]+[`"\]]?))?/i';

        preg_match_all($pattern, $normalised, $matches, PREG_SET_ORDER);

        $found = [];
        $seen = [];

        foreach ($matches as $match) {
            // Strip any database/schema qualifier: "app"."posts" -> posts
            $parts = array_map($this->unquote(...), explode('.', $match[1]));
            $table = (string) end($parts);

            if ($table === '' || is_numeric($table)) {
                continue;
            }

            $alias = isset($match[2]) ? $this->unquote($match[2]) : null;

            if ($alias !== null && in_array(strtolower($alias), self::NOT_ALIASES, true)) {
                $alias = null;
            }

            $signature = $table.'|'.$alias;

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $found[] = ['table' => $table, 'alias' => $alias];
        }

        return $this->memo[$key] = $found;
    }

    /** @return list<string> */
    public function tableNames(string $sql): array
    {
        return array_values(array_unique(array_column($this->tables($sql), 'table')));
    }

    /**
     * Is `$table` constrained by the tenant column in this statement?
     *
     * @param  list<string>  $aliases
     */
    public function hasTenantPredicate(string $sql, string $table, string $column, array $aliases = [], bool $allowUnqualified = false): bool
    {
        $sql = $this->stripLiterals($sql);
        $col = preg_quote($column, '/');

        $qualifiers = array_merge([$table], $aliases);

        foreach ($qualifiers as $qualifier) {
            $q = preg_quote($qualifier, '/');

            if (preg_match('/[`"\[]?\b'.$q.'\b[`"\]]?\s*\.\s*[`"\[]?\b'.$col.'\b[`"\]]?/i', $sql)) {
                return true;
            }
        }

        if ($allowUnqualified && preg_match('/[`"\[]?\b'.$col.'\b[`"\]]?/i', $sql)) {
            return true;
        }

        return false;
    }

    public function isWrite(string $sql): bool
    {
        return (bool) preg_match('/^\s*(insert|update|delete|replace|merge)\b/i', ltrim($sql));
    }

    /**
     * Blank out quoted string literals so their contents can never be mistaken
     * for identifiers.
     */
    protected function stripLiterals(string $sql): string
    {
        return preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? $sql;
    }

    protected function unquote(string $identifier): string
    {
        return trim($identifier, "`\"[] \t\n\r");
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
