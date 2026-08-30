<?php

namespace Ifds\TenantGuard\Sql;

use Illuminate\Database\Eloquent\Model;

/**
 * Knows which tables are tenant-owned.
 *
 * Two sources feed it: the `sentinel.tenant_tables` config array (authoritative,
 * because it is known before any model boots) and models that use the
 * BelongsToTenant trait, which self-register as they boot.
 */
class TenantTableRegistry
{
    /** @var array<string, class-string<Model>> table => model */
    protected array $tables = [];

    /** @var array<string, true> */
    protected array $central = [];

    /**
     * Models registered from inside their own boot cycle, not yet resolved to
     * a table name.
     *
     * getTable() needs an instance, but instantiating a model while it is
     * still booting is refused from Laravel 13 onward (a model may not be
     * constructed from inside its own boot() call). Registration happens
     * exactly there - static::bootTraits() calls bootBelongsToTenant() on the
     * class currently booting - so resolving the table name is deferred until
     * the first read, by which point the model has finished booting.
     *
     * @var list<class-string<Model>>
     */
    protected array $pending = [];

    public function registerModel(string $modelClass): void
    {
        if (! is_a($modelClass, Model::class, true)) {
            return;
        }

        $this->pending[] = $modelClass;
    }

    public function registerTable(string $table, ?string $modelClass = null): void
    {
        $this->resolvePending();

        $this->tables[$table] = $modelClass ?? $this->tables[$table] ?? '';
    }

    public function registerCentralTable(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->central[$table] = true;
        }
    }

    public function isTenantOwned(string $table): bool
    {
        $this->resolvePending();

        return isset($this->tables[$table]) && ! isset($this->central[$table]);
    }

    public function isCentral(string $table): bool
    {
        return isset($this->central[$table]);
    }

    /** @return list<string> */
    public function tables(): array
    {
        $this->resolvePending();

        return array_values(array_diff(array_keys($this->tables), array_keys($this->central)));
    }

    /** @return array<string, class-string<Model>> */
    public function map(): array
    {
        $this->resolvePending();

        return $this->tables;
    }

    public function modelFor(string $table): ?string
    {
        $this->resolvePending();

        return ($this->tables[$table] ?? '') ?: null;
    }

    public function flush(): void
    {
        $this->tables = [];
        $this->central = [];
        $this->pending = [];
    }

    /**
     * Instantiate every pending model to read its table name. Safe here
     * because a read only ever happens once a model's own boot cycle has
     * finished - never from inside bootBelongsToTenant() itself.
     */
    protected function resolvePending(): void
    {
        if ($this->pending === []) {
            return;
        }

        // Swap-and-clear rather than foreach-and-clear, so a model that
        // registers another model as a side effect of its own construction
        // is simply picked up on the next resolution instead of being lost.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $modelClass) {
            $this->tables[(new $modelClass)->getTable()] = $modelClass;
        }
    }
}
