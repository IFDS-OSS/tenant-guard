<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A table that has a tenant_id column but no BelongsToTenant trait - exactly
 * the drift the audit command exists to find.
 */
class LegacyNote extends Model
{
    protected $guarded = [];
}
