<?php

namespace Ifds\TenantGuard\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'tenant-guard:install {--force : Overwrite existing files}';

    protected $description = 'Publish the Tenant Guard config and tenants migration';

    public function handle(): int
    {
        $this->call('vendor:publish', array_filter([
            '--provider' => \Ifds\TenantGuard\TenantGuardServiceProvider::class,
            '--tag' => 'tenant-guard',
            '--force' => $this->option('force') ?: null,
        ]));

        $this->newLine();
        $this->components->info('Tenant Guard installed.');

        $this->components->bulletList([
            'Review config/tenant-guard.php - `missing_context` is the setting that matters most.',
            'Run `php artisan migrate` to create the tenants table.',
            'Add the BelongsToTenant trait to every tenant-owned model.',
            'Put the `tenant` middleware on your tenant routes.',
            'Run `php artisan tenant-guard:audit` and wire it into CI.',
        ]);

        return self::SUCCESS;
    }
}
