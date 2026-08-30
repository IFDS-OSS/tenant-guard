<?php

namespace Ifds\TenantGuard\Resolvers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantResolver;

/**
 * Tries each configured resolver in order and returns the first hit.
 */
class ChainResolver implements TenantResolver
{
    /** @var list<TenantResolver|class-string<TenantResolver>> */
    protected array $resolvers;

    protected ?string $matched = null;

    public function __construct(protected Container $container, array $resolvers = [])
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(Request $request): ?Tenant
    {
        $this->matched = null;

        foreach ($this->resolvers as $resolver) {
            $instance = $resolver instanceof TenantResolver
                ? $resolver
                : $this->container->make($resolver);

            if ($tenant = $instance->resolve($request)) {
                $this->matched = $instance::class;

                return $tenant;
            }
        }

        return null;
    }

    /**
     * Which resolver produced the last result. Useful in logs and tests.
     */
    public function matchedResolver(): ?string
    {
        return $this->matched;
    }

    public function push(TenantResolver|string $resolver): static
    {
        $this->resolvers[] = $resolver;

        return $this;
    }

    public function prepend(TenantResolver|string $resolver): static
    {
        array_unshift($this->resolvers, $resolver);

        return $this;
    }

    /** @return list<TenantResolver|class-string<TenantResolver>> */
    public function resolvers(): array
    {
        return $this->resolvers;
    }
}
