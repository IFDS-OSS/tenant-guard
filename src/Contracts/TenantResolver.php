<?php

namespace Ifds\TenantGuard\Contracts;

use Illuminate\Http\Request;

interface TenantResolver
{
    /**
     * Identify the tenant this request belongs to, or null to defer.
     */
    public function resolve(Request $request): ?Tenant;
}
