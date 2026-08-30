<?php

namespace Ifds\TenantGuard\Concerns;

/**
 * Identical to BelongsToTenant, under a name that does not collide with the
 * `BelongsToTenant` traits shipped by stancl/tenancy and friends.
 *
 *     use Ifds\TenantGuard\Concerns\GuardedByTenant;
 *     use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
 */
trait GuardedByTenant
{
    use BelongsToTenant;
}
