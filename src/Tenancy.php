<?php

namespace Ifds\TenantGuard;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Ifds\TenantGuard\Contracts\Bootstrapper;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Contracts\TenantRepository;
use Ifds\TenantGuard\Events\TenancyBypassed;
use Ifds\TenantGuard\Events\TenantForgotten;
use Ifds\TenantGuard\Events\TenantResolved;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Interop\ForeignTenant;

/**
 * Holds the current tenant for the current process.
 *
 * Registered as a container singleton, so in a normal request/queue worker there
 * is exactly one instance and exactly one answer to "who are we serving?".
 */
class Tenancy implements TenantContext
{
    protected ?Tenant $tenant = null;

    /** @var list<Tenant|null> contexts to unwind, innermost last */
    protected array $stack = [];

    /** Nesting depth of runWithout() calls. */
    protected int $bypass = 0;

    /** @var list<Bootstrapper> */
    protected array $bootstrapped = [];

    public function __construct(
        protected Container $container,
        protected ?Dispatcher $events = null,
    ) {
    }

    /**
     * Resolved lazily rather than injected, so Event::fake() in a test still
     * sees the events this class dispatches.
     */
    protected function events(): Dispatcher
    {
        return $this->container->make('events');
    }

    public function set(object|int|string $tenant): Tenant
    {
        $tenant = $this->resolveTenant($tenant);
        $previous = $this->tenant;

        if ($previous !== null && $this->sameTenant($previous, $tenant)) {
            return $tenant;
        }

        $this->revertBootstrappers();

        $this->tenant = $tenant;
        $this->container->instance(Tenant::class, $tenant);

        $this->runBootstrappers($tenant);

        $this->events()->dispatch(new TenantResolved($tenant, $previous));

        return $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): int|string|null
    {
        return $this->tenant?->getTenantKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContextException(
            'No tenant context is set. Resolve one with TenantGuard::set() or the IdentifyTenant middleware.'
        );
    }

    /**
     * Readable alias for require(), for codebases that dislike keyword methods.
     */
    public function requireTenant(): Tenant
    {
        return $this->require();
    }

    public function forget(): void
    {
        if ($this->tenant === null) {
            return;
        }

        $forgotten = $this->tenant;

        $this->revertBootstrappers();

        $this->tenant = null;
        $this->container->forgetInstance(Tenant::class);

        $this->events()->dispatch(new TenantForgotten($forgotten));
    }

    public function runFor(object|int|string $tenant, Closure $callback): mixed
    {
        $this->stack[] = $this->tenant;

        try {
            $resolved = $this->set($tenant);

            return $callback($resolved);
        } finally {
            $this->restore();
        }
    }

    public function runWithout(Closure $callback): mixed
    {
        $this->events()->dispatch(new TenancyBypassed($this->tenant));

        $this->bypass++;

        try {
            return $callback();
        } finally {
            $this->bypass--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypass > 0;
    }

    /**
     * Run a callback once per tenant. Restores the original context afterwards,
     * even if the callback throws.
     *
     * @param  iterable<Tenant>|null  $tenants
     */
    public function each(Closure $callback, ?iterable $tenants = null): void
    {
        $tenants ??= $this->container->make(TenantRepository::class)->all();

        $original = $this->tenant;
        $this->stack[] = $original;

        try {
            foreach ($tenants as $tenant) {
                $this->set($tenant);
                $callback($tenant);
            }
        } finally {
            $this->restore();
        }
    }

    /**
     * A cache view namespaced to the current tenant, without touching the
     * global cache prefix.
     */
    public function cache(?string $store = null): \Ifds\TenantGuard\Cache\TenantCacheRepository
    {
        return new \Ifds\TenantGuard\Cache\TenantCacheRepository(
            $this->container->make('cache')->store($store),
            $this,
        );
    }

    /**
     * Prepend an application-specific resolution rule to the chain.
     */
    public function resolveUsing(Closure $callback): void
    {
        $resolver = $this->container->make(\Ifds\TenantGuard\Contracts\TenantResolver::class);

        if ($resolver instanceof \Ifds\TenantGuard\Resolvers\ChainResolver) {
            $resolver->prepend(new \Ifds\TenantGuard\Resolvers\ClosureResolver($callback));
        }
    }

    /**
     * Reset everything. Used between queued jobs and by the testing helpers.
     */
    public function reset(): void
    {
        $this->forget();
        $this->stack = [];
        $this->bypass = 0;
    }

    protected function restore(): void
    {
        $previous = array_pop($this->stack);

        $previous === null ? $this->forget() : $this->set($previous);
    }

    protected function resolveTenant(object|int|string $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        // A tenant model from another package (spatie, stancl, home-grown) is
        // adapted rather than rejected.
        if (is_object($tenant)) {
            return ForeignTenant::wrap($tenant);
        }

        return $this->container->make(TenantRepository::class)->findOrFail($tenant);
    }

    protected function sameTenant(Tenant $a, Tenant $b): bool
    {
        return $a::class === $b::class && $a->getTenantKey() === $b->getTenantKey();
    }

    /**
     * @return list<class-string<Bootstrapper>>
     */
    protected function enabledBootstrappers(): array
    {
        $configured = (array) $this->container->make('config')->get('tenant-guard.bootstrappers', []);

        $enabled = [];

        foreach ($configured as $class => $isEnabled) {
            // Support both ['Class' => true] and a plain list of class names.
            if (is_int($class)) {
                $enabled[] = $isEnabled;
            } elseif ($isEnabled) {
                $enabled[] = $class;
            }
        }

        return $enabled;
    }

    protected function runBootstrappers(Tenant $tenant): void
    {
        foreach ($this->enabledBootstrappers() as $class) {
            $bootstrapper = $this->container->make($class);
            $bootstrapper->bootstrap($tenant);
            $this->bootstrapped[] = $bootstrapper;
        }
    }

    protected function revertBootstrappers(): void
    {
        foreach (array_reverse($this->bootstrapped) as $bootstrapper) {
            $bootstrapper->revert();
        }

        $this->bootstrapped = [];
    }
}
