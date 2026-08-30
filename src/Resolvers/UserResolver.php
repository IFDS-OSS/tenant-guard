<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;

/**
 * The authenticated user's own tenant. The safest resolver of the bunch, since
 * the value comes from the session or token rather than from the request.
 */
class UserResolver extends Resolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if ($user instanceof Tenant) {
            return $user;
        }

        $attribute = $this->setting('user_attribute', 'tenant_id');

        return $this->lookup(data_get($user, $attribute));
    }
}
