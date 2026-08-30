<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\PlanFactory;

/**
 * Deliberately central: shared by every tenant, never scoped. Its presence in
 * the suite proves Tenant Guard does not over-reach.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
