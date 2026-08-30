<?php

namespace Ifds\TenantGuard\Console;

use Illuminate\Console\Command;
use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Contracts\TenantRepository;

/**
 * Run any artisan command inside one or more tenant contexts.
 *
 *     php artisan tenant-guard:run "posts:reindex" --tenant=acme
 *     php artisan tenant-guard:run "posts:reindex" --all
 */
class RunForTenantCommand extends Command
{
    protected $signature = 'tenant-guard:run
                            {cmd : The artisan command to run, quoted}
                            {--tenant=* : Tenant key, slug or domain (repeatable)}
                            {--all : Run once for every tenant}';

    protected $description = 'Run an artisan command inside a tenant context';

    public function handle(TenantContext $tenancy, TenantRepository $tenants): int
    {
        $targets = $this->option('all')
            ? $tenants->all()->all()
            : array_map(
                fn ($key) => $tenants->findByIdentifierOrFail($key),
                (array) $this->option('tenant')
            );

        if ($targets === []) {
            $this->error('Specify --tenant=... or --all.');

            return self::INVALID;
        }

        $exit = self::SUCCESS;

        foreach ($targets as $tenant) {
            $this->components->task(
                sprintf('tenant %s', $tenant->getTenantKey()),
                function () use ($tenancy, $tenant, &$exit) {
                    $code = $tenancy->runFor($tenant, fn () => $this->runArtisan());

                    if ($code !== 0) {
                        $exit = self::FAILURE;
                    }

                    return $code === 0;
                }
            );
        }

        return $exit;
    }

    protected function runArtisan(): int
    {
        $parts = str_getcsv((string) $this->argument('cmd'), ' ', '"', '\\');
        $name = array_shift($parts);

        return $this->call($name, $this->parseArguments($parts));
    }

    /**
     * @param  list<string|null>  $parts
     */
    protected function parseArguments(array $parts): array
    {
        $arguments = [];

        foreach (array_filter($parts, fn ($part) => $part !== null && $part !== '') as $part) {
            if (str_starts_with($part, '--')) {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, true);
                $arguments[$key] = $value;
            } else {
                $arguments[] = $part;
            }
        }

        return $arguments;
    }
}
