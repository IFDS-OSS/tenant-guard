<?php

namespace Workbench\App\Interop;

use Spatie\Multitenancy\Models\Tenant as SpatieBaseTenant;

/**
 * A spatie/laravel-multitenancy tenant living on the same `tenants` table
 * Tenant Guard uses. This is the realistic hybrid setup: Spatie decides who the
 * current tenant is, Tenant Guard enforces row-level isolation for the tables
 * that stay on the shared connection.
 */
class SpatieTenant extends SpatieBaseTenant
{
    protected $table = 'tenants';

    protected $guarded = [];

    public $timestamps = true;
}
