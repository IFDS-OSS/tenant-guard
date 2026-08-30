<?php

namespace Ifds\TenantGuard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Contracts\TenantContext;

/**
 * Layer 5 - the tenant-aware replacement for `unique:`.
 *
 * In a shared schema a slug only has to be unique *within* a tenant. Plain
 * `unique:posts,slug` is both too strict (it collides across tenants) and
 * leaks the existence of other tenants' rows.
 *
 *     'slug' => ['required', (new UniqueForTenant(Post::class, 'slug'))->ignore($post)],
 */
class UniqueForTenant implements ValidationRule
{
    protected mixed $ignoreId = null;

    protected string $ignoreColumn = 'id';

    protected ?Closure $modifier = null;

    public function __construct(
        protected string $target,
        protected ?string $column = null,
    ) {
    }

    public function ignore(mixed $id, string $column = 'id'): static
    {
        $this->ignoreId = $id instanceof Model ? $id->getKey() : $id;
        $this->ignoreColumn = $id instanceof Model ? $id->getKeyName() : $column;

        return $this;
    }

    public function where(Closure $modifier): static
    {
        $this->modifier = $modifier;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $tenancy = app(TenantContext::class);

        if (! $tenancy->check()) {
            $fail('tenant-guard::validation.unique_for_tenant')->translate();

            return;
        }

        $column = $this->column ?? $attribute;

        $query = is_a($this->target, Model::class, true)
            ? (new $this->target)->newQuery()
            : DB::table($this->target)->where(
                config('tenant-guard.tenant_key', 'tenant_id'),
                $tenancy->id()
            );

        $query->where($column, $value);

        if ($this->ignoreId !== null) {
            $query->where($this->ignoreColumn, '!=', $this->ignoreId);
        }

        if ($this->modifier) {
            ($this->modifier)($query);
        }

        if ($query->exists()) {
            $fail('tenant-guard::validation.unique_for_tenant')->translate();
        }
    }
}
