<?php

namespace Ifds\TenantGuard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ifds\TenantGuard\Contracts\TenantContext;

/**
 * Layer 5 - the tenant-aware replacement for `exists:`.
 *
 * `exists:posts,id` happily confirms another tenant's row, which turns a
 * validation error message into an object-enumeration oracle.
 *
 *     'post_id' => ['required', new TenantOwned(Post::class)],
 *     'post_id' => ['required', new TenantOwned('posts')],
 */
class TenantOwned implements ValidationRule
{
    protected ?Closure $modifier = null;

    public function __construct(
        protected string $target,
        protected string $column = 'id',
    ) {
    }

    /**
     * Narrow the lookup further, e.g. to published rows only.
     */
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
            $fail('tenant-guard::validation.tenant_owned')->translate();

            return;
        }

        $query = is_a($this->target, Model::class, true)
            ? (new $this->target)->newQuery()
            : DB::table($this->target)->where(
                config('tenant-guard.tenant_key', 'tenant_id'),
                $tenancy->id()
            );

        $query->whereIn($this->column, (array) $value);

        if ($this->modifier) {
            ($this->modifier)($query);
        }

        if ($query->count() !== count((array) $value)) {
            $fail('tenant-guard::validation.tenant_owned')->translate();
        }
    }
}
