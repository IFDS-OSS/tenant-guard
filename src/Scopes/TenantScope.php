<?php

namespace Ifds\TenantGuard\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Events\CrossTenantAccessDenied;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;

/**
 * Layer 1 - constrains every Eloquent query on a tenant-owned model to the
 * current tenant.
 *
 * Global scopes are applied lazily, when the query is executed rather than when
 * it is built, so building a query outside a tenant context is harmless; only
 * running it is refused.
 */
class TenantScope implements Scope
{
    public const IDENTIFIER = TenantScope::class;

    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $tenancy */
        $tenancy = app(TenantContext::class);

        if ($tenancy->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn($model->getTenantColumn());

        if ($tenancy->check()) {
            $builder->where($column, '=', $tenancy->id());

            return;
        }

        match (config('tenant-guard.missing_context', 'throw')) {
            'ignore' => $this->warn($model),
            'empty' => $builder->whereRaw('1 = 0'),
            default => $this->refuse($model),
        };
    }

    /**
     * Adds the escape hatches to the builder for models using the scope.
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });

        $builder->macro('allTenants', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });

        $builder->macro('forTenant', function (Builder $builder, mixed $tenant) {
            $model = $builder->getModel();

            $key = $tenant instanceof \Ifds\TenantGuard\Contracts\Tenant
                ? $tenant->getTenantKey()
                : ($tenant instanceof Model ? $tenant->getKey() : $tenant);

            return $builder->withoutGlobalScope(static::class)
                ->where($model->qualifyColumn($model->getTenantColumn()), '=', $key);
        });
    }

    protected function refuse(Model $model): void
    {
        event(new CrossTenantAccessDenied(
            layer: 'query-scope',
            subject: $model::class,
            currentTenant: null,
            reason: 'no tenant context',
        ));

        throw MissingTenantContextException::forQuery($model::class);
    }

    protected function warn(Model $model): void
    {
        logger()?->warning('[tenant-guard] Unscoped query on tenant-owned model.', [
            'model' => $model::class,
            'table' => $model->getTable(),
        ]);
    }
}
