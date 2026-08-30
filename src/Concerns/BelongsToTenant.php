<?php

namespace Ifds\TenantGuard\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Events\CrossTenantAccessDenied;
use Ifds\TenantGuard\Exceptions\CrossTenantWriteException;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Scopes\TenantScope;
use Ifds\TenantGuard\Sql\TenantTableRegistry;

/**
 * Marks an Eloquent model as tenant-owned.
 *
 * Adding this trait wires up layer 1 (the query scope) and layer 2 (the write
 * guard), and registers the model's table with the SQL Sentinel.
 *
 * @method static Builder withoutTenantScope()
 * @method static Builder allTenants()
 * @method static Builder forTenant(mixed $tenant)
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        app(TenantTableRegistry::class)->registerModel(static::class);

        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $model->guardTenantOnCreate();
        });

        // Fires before `updating`, so an attempt to move a row between tenants
        // is rejected before the ownership check even runs.
        static::saving(function (Model $model): void {
            $model->guardTenantKeyIsImmutable();
        });

        static::updating(function (Model $model): void {
            $model->guardTenantOnWrite('update');
        });

        static::deleting(function (Model $model): void {
            $model->guardTenantOnWrite('delete');
        });
    }

    /**
     * The discriminator column on this model's table.
     */
    public function getTenantColumn(): string
    {
        return property_exists($this, 'tenantColumn') && $this->tenantColumn
            ? $this->tenantColumn
            : config('tenant-guard.tenant_key', 'tenant_id');
    }

    public function getTenantId(): int|string|null
    {
        return $this->getAttribute($this->getTenantColumn());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            config('tenant-guard.tenant_model', \Ifds\TenantGuard\Models\Tenant::class),
            $this->getTenantColumn()
        );
    }

    /**
     * True when the row belongs to the tenant currently in context.
     */
    public function belongsToCurrentTenant(): bool
    {
        $current = static::tenancy()->id();

        return $current !== null && $this->compareKeys($this->getTenantId(), $current);
    }

    // ---------------------------------------------------------------------
    // Layer 2 - write guard
    // ---------------------------------------------------------------------

    public function guardTenantOnCreate(): void
    {
        $tenancy = static::tenancy();
        $column = $this->getTenantColumn();
        $given = $this->getAttribute($column);
        $current = $tenancy->id();

        // Inside runWithout() an explicit tenant id is trusted - that is how
        // seeders and cross-tenant maintenance scripts are meant to work.
        if ($tenancy->isBypassed()) {
            if ($given === null && $current !== null) {
                $this->setAttribute($column, $current);
            } elseif ($given === null) {
                throw MissingTenantContextException::forWrite(static::class);
            }

            return;
        }

        if ($current === null) {
            $this->denied('write-guard', null, 'no tenant context on create');

            throw MissingTenantContextException::forWrite(static::class);
        }

        if ($given === null) {
            $this->setAttribute($column, $current);

            return;
        }

        if (! $this->compareKeys($given, $current)) {
            $this->denied('write-guard', $given, 'create with a foreign tenant id');

            throw CrossTenantWriteException::make(static::class, $given, $current, 'create');
        }
    }

    public function guardTenantOnWrite(string $operation): void
    {
        $tenancy = static::tenancy();

        if ($tenancy->isBypassed()) {
            return;
        }

        $column = $this->getTenantColumn();
        $current = $tenancy->id();

        if ($current === null) {
            $this->denied('write-guard', null, "no tenant context on {$operation}");

            throw MissingTenantContextException::forWrite(static::class);
        }

        // getOriginal() is what is actually on the row, not what was assigned.
        $owner = $this->getOriginal($column, $this->getAttribute($column));

        if (! $this->compareKeys($owner, $current)) {
            $this->denied('write-guard', $owner, "cross-tenant {$operation}");

            throw CrossTenantWriteException::make(static::class, $owner, $current, $operation);
        }
    }

    public function guardTenantKeyIsImmutable(): void
    {
        if (static::tenancy()->isBypassed() || ! $this->exists) {
            return;
        }

        $column = $this->getTenantColumn();

        if ($this->isDirty($column) && ! $this->compareKeys(
            $this->getOriginal($column),
            $this->getAttribute($column)
        )) {
            $this->denied('write-guard', $this->getOriginal($column), 'tenant key mutation');

            throw CrossTenantWriteException::immutableKey(static::class, $column);
        }
    }

    // ---------------------------------------------------------------------
    // Escape hatches (also available as builder macros via TenantScope)
    // ---------------------------------------------------------------------

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    public function scopeForTenant(Builder $query, mixed $tenant): Builder
    {
        $key = match (true) {
            $tenant instanceof Tenant => $tenant->getTenantKey(),
            $tenant instanceof Model => $tenant->getKey(),
            default => $tenant,
        };

        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn($this->getTenantColumn()), '=', $key);
    }

    protected static function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Compare keys loosely enough that "3" from a request matches 3 from the
     * database, but strictly enough that null never matches anything.
     */
    protected function compareKeys(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return (string) $a === (string) $b;
    }

    protected function denied(string $layer, mixed $rowTenant, string $reason): void
    {
        event(new CrossTenantAccessDenied(
            layer: $layer,
            subject: static::class,
            currentTenant: static::tenancy()->id(),
            reason: $reason.($rowTenant === null ? '' : " (row tenant: {$rowTenant})"),
        ));
    }
}
