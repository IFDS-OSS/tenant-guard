<?php

namespace Ifds\TenantGuard\Exceptions;

/**
 * No tenant matched the request, or a lookup by key found nothing.
 */
class TenantNotFoundException extends TenantGuardException
{
    public static function forKey(int|string $key): self
    {
        return new self("No tenant found for key [{$key}].");
    }

    public static function forAttribute(string $attribute, mixed $value): self
    {
        return new self(sprintf('No tenant found where [%s] is [%s].', $attribute, (string) $value));
    }

    public static function forRequest(string $host): self
    {
        return new self("Could not resolve a tenant for [{$host}].");
    }
}
