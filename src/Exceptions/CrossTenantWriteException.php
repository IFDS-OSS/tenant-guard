<?php

namespace Ifds\TenantGuard\Exceptions;

/**
 * A write was attempted against a row belonging to a different tenant.
 */
class CrossTenantWriteException extends TenantGuardException
{
    public static function make(string $model, mixed $rowTenant, mixed $currentTenant, string $operation): self
    {
        return new self(sprintf(
            'Blocked %s on [%s]: the row belongs to tenant [%s] but the current tenant is [%s].',
            $operation,
            $model,
            $rowTenant === null ? 'null' : (string) $rowTenant,
            $currentTenant === null ? 'null' : (string) $currentTenant,
        ));
    }

    public static function immutableKey(string $model, string $column): self
    {
        return new self(
            "Blocked change to [{$model}::\${$column}]: the tenant key is immutable. Move the row "
            .'by deleting and recreating it inside the target tenant, deliberately.'
        );
    }
}
