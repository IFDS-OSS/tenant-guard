<?php

namespace Ifds\TenantGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Contracts\TenantResolver;
use Ifds\TenantGuard\Exceptions\TenantNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the tenant for the incoming request and establishes the context.
 *
 *     Route::middleware('tenant')->group(...);
 *     Route::middleware('tenant:required')->group(...);
 */
class IdentifyTenant
{
    public function __construct(
        protected TenantResolver $resolver,
        protected TenantContext $tenancy,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant !== null) {
            $this->tenancy->set($tenant);

            return $next($request);
        }

        $required = $mode === 'required'
            || config('tenant-guard.resolution.required', false);

        if (! $required) {
            return $next($request);
        }

        throw $this->notFound($request);
    }

    /**
     * Clear the context once the response has been sent. Matters for Octane,
     * Swoole and anything else that reuses the container between requests.
     */
    public function terminate(Request $request, $response): void
    {
        $this->tenancy->forget();
    }

    protected function notFound(Request $request): \Throwable
    {
        $exception = TenantNotFoundException::forRequest($request->getHost());

        return config('tenant-guard.resolution.abort_404', true)
            ? new NotFoundHttpException($exception->getMessage(), $exception)
            : $exception;
    }
}
