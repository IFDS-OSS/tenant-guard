<?php

namespace Ifds\TenantGuard\Interop;

use Illuminate\Database\Eloquent\Model;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * Wraps a tenant model owned by another package - spatie/laravel-multitenancy,
 * stancl/tenancy, or an in-house one - so Tenant Guard can use it without the
 * host application having to implement our contract on someone else's class.
 *
 * All property and method access is forwarded to the wrapped model, so
 * `TenantGuard::current()->name` keeps working.
 *
 * @mixin Model
 */
class ForeignTenant implements Tenant
{
    public function __construct(
        protected object $model,
        protected ?string $keyName = null,
    ) {
    }

    public static function wrap(object $model, ?string $keyName = null): Tenant
    {
        return $model instanceof Tenant ? $model : new self($model, $keyName);
    }

    public function getTenantKey(): int|string
    {
        $key = $this->keyName !== null
            ? $this->model->{$this->keyName}
            : ($this->model instanceof Model ? $this->model->getKey() : ($this->model->id ?? null));

        if ($key === null) {
            throw new \InvalidArgumentException(sprintf(
                'Could not read a tenant key from [%s]. Pass the key name explicitly: '
                .'ForeignTenant::wrap($tenant, \'uuid\').',
                $this->model::class,
            ));
        }

        return $key;
    }

    public function getTenantKeyName(): string
    {
        return $this->keyName
            ?? ($this->model instanceof Model ? $this->model->getKeyName() : 'id');
    }

    public function unwrap(): object
    {
        return $this->model;
    }

    public function __get(string $name): mixed
    {
        return $this->model->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->model->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->model->{$name});
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->model->{$method}(...$arguments);
    }
}
