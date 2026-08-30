<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\Database\Seeders\DatabaseSeeder;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `testbench migrate --seed` resolves the conventional app seeder name
        // and falls back to the bare `DatabaseSeeder` when that class is
        // missing. Bind both at the workbench seeder.
        foreach (['Database\\Seeders\\DatabaseSeeder', 'DatabaseSeeder'] as $alias) {
            $this->app->bind($alias, fn () => new DatabaseSeeder);
        }
    }

    public function boot(): void
    {
        $config = $this->app['config'];

        // Point the audit at the workbench's models rather than the testbench
        // skeleton's empty app/Models directory.
        $config->set('tenant-guard.audit.model_paths', [dirname(__DIR__, 2).'/app/Models']);

        // Declare the genuinely central tables. What is left over - legacy_notes
        // and unclassified_widgets - is deliberate drift, so `tenant-guard:audit`
        // has something real to find.
        $config->set('tenant-guard.audit.ignored_tables', array_merge(
            (array) $config->get('tenant-guard.audit.ignored_tables', []),
            ['plans', 'stancl_tenants'],
        ));
    }
}
