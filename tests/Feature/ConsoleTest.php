<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Post;

class ConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 5);

        Artisan::command('workbench:count-posts', function () {
            $this->line(TenantGuard::id().':'.Post::count());
        });
    }

    public function test_run_executes_a_command_inside_one_tenant(): void
    {
        $output = $this->artisanOutput('tenant-guard:run', [
            'cmd' => 'workbench:count-posts',
            '--tenant' => ['acme'],
        ]);

        $this->assertStringContainsString("{$this->acme->id}:2", $output);
    }

    public function test_run_executes_a_command_for_every_tenant(): void
    {
        $output = $this->artisanOutput('tenant-guard:run', [
            'cmd' => 'workbench:count-posts',
            '--all' => true,
        ]);

        $this->assertStringContainsString("{$this->acme->id}:2", $output);
        $this->assertStringContainsString("{$this->globex->id}:5", $output);
    }

    public function test_run_restores_the_context_afterwards(): void
    {
        TenantGuard::set($this->acme);

        Artisan::call('tenant-guard:run', ['cmd' => 'workbench:count-posts', '--all' => true]);

        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_run_requires_a_target(): void
    {
        $this->assertSame(
            Command::INVALID,
            Artisan::call('tenant-guard:run', ['cmd' => 'workbench:count-posts'])
        );
    }

    public function test_run_fails_for_an_unknown_tenant(): void
    {
        $this->expectException(\Ifds\TenantGuard\Exceptions\TenantNotFoundException::class);

        Artisan::call('tenant-guard:run', [
            'cmd' => 'workbench:count-posts',
            '--tenant' => ['nope'],
        ]);
    }

    public function test_install_publishes_the_config_and_migration(): void
    {
        $config = $this->app->configPath('tenant-guard.php');
        $migrations = $this->app->databasePath('migrations');

        @unlink($config);
        $before = glob($migrations.'/*_create_tenants_table.php') ?: [];

        try {
            Artisan::call('tenant-guard:install');

            $after = glob($migrations.'/*_create_tenants_table.php') ?: [];

            $this->assertFileExists($config);
            $this->assertGreaterThan(count($before), count($after), 'the tenants migration should be published');
        } finally {
            // Publishing into Testbench's skeleton would make the tenants
            // migration run twice for every later test. Always clean up.
            @unlink($config);

            foreach (array_diff(glob($migrations.'/*_create_tenants_table.php') ?: [], $before) as $published) {
                @unlink($published);
            }
        }
    }

    public function test_the_list_command_can_emit_json(): void
    {
        $rows = $this->artisanJson('tenant-guard:list', ['--json' => true]);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['acme', 'globex'], array_column($rows, 'slug'));
    }
}
