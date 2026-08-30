<?php

namespace Ifds\TenantGuard\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Ifds\TenantGuard\Contracts\Tenant;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Exceptions\CrossTenantWriteException;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Scopes\TenantScope;
use Ifds\TenantGuard\Sql\SqlSentinel;
use PHPUnit\Framework\Assert;

/**
 * Test helpers. Mix into a TestCase to write tenancy assertions that read like
 * the thing you are actually worried about.
 */
trait InteractsWithTenancy
{
    protected function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Re-arm queue payload propagation.
     *
     * Laravel and Testbench flush `Queue::createPayloadUsing()` while resetting
     * global state between tests, which would silently strip the tenant from
     * queued payloads. Call this from setUp() in any test that queues work.
     */
    protected function bootQueuePropagation(): void
    {
        app(\Ifds\TenantGuard\Queue\TenantQueueBridge::class)->register();
    }

    /**
     * Establish a tenant context for the rest of the test.
     */
    protected function actingAsTenant(object|int|string $tenant): Tenant
    {
        return $this->tenancy()->set($tenant);
    }

    protected function actingWithoutTenant(): void
    {
        $this->tenancy()->forget();
    }

    protected function withinTenant(object|int|string $tenant, Closure $callback): mixed
    {
        return $this->tenancy()->runFor($tenant, $callback);
    }

    protected function withoutTenancy(Closure $callback): mixed
    {
        return $this->tenancy()->runWithout($callback);
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    /**
     * The model's generated SQL constrains the tenant column.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertTenantScoped(string $modelClass, string $message = ''): void
    {
        $model = new $modelClass;

        Assert::assertTrue(
            method_exists($model, 'getTenantColumn'),
            $message ?: "[{$modelClass}] does not use the BelongsToTenant trait."
        );

        $sql = $modelClass::query()->toSql();
        $column = $model->getTenantColumn();

        Assert::assertMatchesRegularExpression(
            '/[`"\[]?'.preg_quote($column, '/').'[`"\]]?\s*=/i',
            $sql,
            $message ?: "Queries on [{$modelClass}] are not constrained by [{$column}]. SQL: {$sql}"
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function assertNotTenantScoped(string $modelClass, string $message = ''): void
    {
        $model = new $modelClass;

        if (! method_exists($model, 'getTenantColumn')) {
            Assert::assertTrue(true);

            return;
        }

        Assert::assertDoesNotMatchRegularExpression(
            '/[`"\[]?'.preg_quote($model->getTenantColumn(), '/').'[`"\]]?\s*=/i',
            $modelClass::query()->toSql(),
            $message ?: "[{$modelClass}] is tenant-scoped but was expected to be central."
        );
    }

    /**
     * Run a callback and assert the SQL Sentinel saw no unscoped query.
     */
    protected function assertNoUnscopedQueries(Closure $callback, string $message = ''): void
    {
        $sentinel = app(SqlSentinel::class);

        $previous = config('tenant-guard.sentinel.mode');
        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_LOG]);

        $sentinel->record();

        try {
            $callback();
            $violations = $sentinel->recorded();
        } finally {
            $sentinel->stopRecording();
            config(['tenant-guard.sentinel.mode' => $previous]);
        }

        Assert::assertSame(
            [],
            $violations,
            $message ?: 'Unscoped queries reached the database: '
                .json_encode(array_column($violations, 'sql'), JSON_PRETTY_PRINT)
        );
    }

    /**
     * The inverse: prove a deliberately bad call really is caught.
     */
    protected function assertUnscopedQueryDetected(Closure $callback, string $message = ''): void
    {
        $sentinel = app(SqlSentinel::class);

        $previous = config('tenant-guard.sentinel.mode');
        config(['tenant-guard.sentinel.mode' => SqlSentinel::MODE_LOG]);

        $sentinel->record();

        try {
            $callback();
            $violations = $sentinel->recorded();
        } finally {
            $sentinel->stopRecording();
            config(['tenant-guard.sentinel.mode' => $previous]);
        }

        Assert::assertNotSame([], $violations, $message ?: 'Expected the SQL Sentinel to flag a query.');
    }

    /**
     * Assert that a callback fails closed - it must not silently return data.
     */
    protected function assertFailsClosed(Closure $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (MissingTenantContextException|CrossTenantWriteException) {
            Assert::assertTrue(true);

            return;
        }

        Assert::fail($message ?: 'Expected the operation to be refused, but it succeeded.');
    }

    /**
     * A row created for one tenant is invisible to another.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertIsolatedBetween(
        string $modelClass,
        object|int|string $owner,
        object|int|string $other,
        Closure $factory,
    ): void {
        $model = $this->withinTenant($owner, fn () => $factory());

        Assert::assertInstanceOf(Model::class, $model, 'The factory must return a model.');

        $this->withinTenant($other, function () use ($modelClass, $model) {
            Assert::assertNull(
                $modelClass::find($model->getKey()),
                "[{$modelClass}] #{$model->getKey()} is visible to the wrong tenant."
            );
        });

        $this->withinTenant($owner, function () use ($modelClass, $model) {
            Assert::assertNotNull(
                $modelClass::find($model->getKey()),
                "[{$modelClass}] #{$model->getKey()} is not visible to its own tenant."
            );
        });
    }

    /**
     * Count rows across every tenant, bypassing the scope on purpose.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function countAllTenants(string $modelClass): int
    {
        return $this->withoutTenancy(
            fn () => $modelClass::query()->withoutGlobalScope(TenantScope::class)->count()
        );
    }
}
