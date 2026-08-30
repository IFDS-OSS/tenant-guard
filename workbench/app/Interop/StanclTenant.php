<?php

namespace Workbench\App\Interop;

use Stancl\Tenancy\Database\Models\Tenant as StanclBaseTenant;

/**
 * A stancl/tenancy tenant on its own table.
 *
 * stancl keys tenants by a string id and stores everything else in a JSON
 * `data` column, so it gets its own table rather than sharing Tenant Guard's.
 * The two systems meet at the tenant *key*, which is all Tenant Guard needs.
 */
class StanclTenant extends StanclBaseTenant
{
    protected $table = 'stancl_tenants';
}
