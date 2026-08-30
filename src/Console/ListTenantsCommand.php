<?php

namespace Ifds\TenantGuard\Console;

use Illuminate\Console\Command;
use Ifds\TenantGuard\Contracts\TenantRepository;

class ListTenantsCommand extends Command
{
    protected $signature = 'tenant-guard:list {--json}';

    protected $description = 'List every tenant known to Tenant Guard';

    public function handle(TenantRepository $tenants): int
    {
        $rows = $tenants->all()->map(fn ($tenant) => [
            'key' => $tenant->getTenantKey(),
            'name' => $tenant->name ?? '-',
            'slug' => $tenant->slug ?? '-',
            'domain' => $tenant->domain ?? '-',
        ])->all();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $this->table(['Key', 'Name', 'Slug', 'Domain'], $rows);

        return self::SUCCESS;
    }
}
