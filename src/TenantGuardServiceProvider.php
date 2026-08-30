<?php

namespace Ifds\TenantGuard;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Ifds\TenantGuard\Console\AuditCommand;
use Ifds\TenantGuard\Console\InstallCommand;
use Ifds\TenantGuard\Console\ListTenantsCommand;
use Ifds\TenantGuard\Console\RunForTenantCommand;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Contracts\TenantRepository as TenantRepositoryContract;
use Ifds\TenantGuard\Contracts\TenantResolver;
use Ifds\TenantGuard\Http\Middleware\IdentifyTenant;
use Ifds\TenantGuard\Http\Middleware\PreventTenantAccess;
use Ifds\TenantGuard\Http\Middleware\RequireTenant;
use Ifds\TenantGuard\Interop\InteropServiceBinder;
use Ifds\TenantGuard\Listeners\ForgetTenantAfterJob;
use Ifds\TenantGuard\Listeners\PropagateTenantToJob;
use Ifds\TenantGuard\Queue\TenantQueueBridge;
use Ifds\TenantGuard\Resolvers\ChainResolver;
use Ifds\TenantGuard\Sql\SqlAnalyzer;
use Ifds\TenantGuard\Sql\SqlSentinel;
use Ifds\TenantGuard\Sql\TenantTableRegistry;

class TenantGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenant-guard.php', 'tenant-guard');

        $this->app->singleton(Tenancy::class, fn ($app) => new Tenancy($app, $app['events']));
        $this->app->alias(Tenancy::class, TenantContext::class);
        $this->app->alias(Tenancy::class, 'tenant-guard');

        $this->app->singleton(TenantTableRegistry::class);
        $this->app->singleton(SqlAnalyzer::class);

        $this->app->singleton(TenantRepositoryContract::class, fn ($app) => new TenantRepository(
            $app['config'],
            $app['cache'],
        ));
        $this->app->alias(TenantRepositoryContract::class, TenantRepository::class);

        $this->app->singleton(SqlSentinel::class, fn ($app) => new SqlSentinel(
            $app->make(SqlAnalyzer::class),
            $app->make(TenantTableRegistry::class),
            $app->make(TenantContext::class),
            $app['config'],
            $app['events'],
            $app->bound('log') ? $app['log'] : null,
        ));

        $this->app->singleton(TenantResolver::class, fn ($app) => new ChainResolver(
            $app,
            (array) $app['config']->get('tenant-guard.resolvers', []),
        ));
        $this->app->alias(TenantResolver::class, ChainResolver::class);

        $this->app->singleton(TenantQueueBridge::class, fn ($app) => new TenantQueueBridge(
            $app->make(TenantContext::class),
            $app['config'],
        ));

        $this->app->singleton(InteropServiceBinder::class, fn ($app) => new InteropServiceBinder(
            $app->make(TenantContext::class),
            $app['events'],
            $app['config'],
        ));
    }

    public function boot(): void
    {
        $this->bootPublishing();
        $this->bootTranslations();
        $this->bootMiddleware();
        $this->bootSentinel();
        $this->bootQueue();
        $this->bootInterop();
        $this->bootCommands();
        $this->bootCentralTables();
    }

    protected function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/tenant-guard.php' => $this->app->configPath('tenant-guard.php'),
        ], ['tenant-guard', 'tenant-guard-config']);

        $this->publishes([
            __DIR__.'/../database/migrations/0001_01_01_000001_create_tenants_table.php' => $this->app->databasePath(
                'migrations/'.date('Y_m_d_His').'_create_tenants_table.php'
            ),
        ], ['tenant-guard', 'tenant-guard-migrations']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/tenant-guard'),
        ], ['tenant-guard', 'tenant-guard-translations']);
    }

    protected function bootTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'tenant-guard');

        if ($this->app['config']->get('tenant-guard.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function bootMiddleware(): void
    {
        if (! $this->app->bound(Router::class) && ! $this->app->bound('router')) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->aliasMiddleware('tenant', IdentifyTenant::class);
        $router->aliasMiddleware('tenant.required', RequireTenant::class);
        $router->aliasMiddleware('tenant.central', PreventTenantAccess::class);

        $router->middlewareGroup('tenant-guard', [
            IdentifyTenant::class,
            RequireTenant::class,
        ]);
    }

    /**
     * Layer 3 - attach to every database connection, present and future.
     */
    protected function bootSentinel(): void
    {
        $sentinel = fn () => $this->app->make(SqlSentinel::class);

        $this->app['events']->listen(ConnectionEstablished::class, function ($event) use ($sentinel) {
            if ($event->connection instanceof Connection) {
                $sentinel()->watch($event->connection);
            }
        });

        // Connections resolved before this provider booted.
        $db = $this->app->bound('db') ? $this->app['db'] : null;

        if ($db === null) {
            return;
        }

        foreach ($db->getConnections() as $connection) {
            if ($connection instanceof Connection) {
                $sentinel()->watch($connection);
            }
        }
    }

    /**
     * Layer 4 - carry the tenant across the queue boundary.
     */
    protected function bootQueue(): void
    {
        $this->app->make(TenantQueueBridge::class)->register();

        $events = $this->app['events'];

        $events->listen(JobProcessing::class, [PropagateTenantToJob::class, 'handle']);

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            $events->listen($event, [ForgetTenantAfterJob::class, 'handle']);
        }
    }

    protected function bootInterop(): void
    {
        $this->app->make(InteropServiceBinder::class)->register();
    }

    protected function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            AuditCommand::class,
            ListTenantsCommand::class,
            RunForTenantCommand::class,
        ]);
    }

    protected function bootCentralTables(): void
    {
        $this->app->make(TenantTableRegistry::class)->registerCentralTable(
            ...(array) $this->app['config']->get('tenant-guard.sentinel.central_tables', []),
            ...[(string) $this->app['config']->get('tenant-guard.tenants_table', 'tenants')],
        );
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [
            Tenancy::class,
            TenantContext::class,
            TenantResolver::class,
            TenantRepositoryContract::class,
            SqlSentinel::class,
            TenantTableRegistry::class,
        ];
    }
}
