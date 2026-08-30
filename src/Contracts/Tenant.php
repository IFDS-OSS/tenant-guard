<?php

namespace Ifds\TenantGuard\Contracts;

/**
 * Implemented by whatever model represents a tenant in the host application.
 */
interface Tenant
{
    /**
     * The value stored in the tenant_id column of tenant-owned rows.
     */
    public function getTenantKey(): int|string;

    /**
     * The name of the column holding that value on the tenant model itself.
     */
    public function getTenantKeyName(): string;
}
