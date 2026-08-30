<?php

namespace Workbench\App\Interop;

use Illuminate\Database\Eloquent\Model;

/**
 * A home-grown tenant model that knows nothing about Tenant Guard: no contract,
 * no trait, an unconventional key name. Tenant Guard still accepts it.
 */
class CustomTenant extends Model
{
    protected $table = 'tenants';

    protected $guarded = [];

    public function organisationRef(): string
    {
        return 'org-'.$this->getKey();
    }
}
