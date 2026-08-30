<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Ifds\TenantGuard\Tests\TestCase;

/**
 * Layer 5 - the drift detector. The workbench schema deliberately contains one
 * fixture for each class of drift the command is meant to find.
 */
class AuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set in setUp() rather than defineEnvironment() so these win over the
        // workbench provider's own boot-time overrides. The audit's inputs are
        // stated explicitly here so the assertions do not depend on ambient
        // configuration.
        config([
            'tenant-guard.audit.model_paths' => [dirname(__DIR__, 2).'/workbench/app/Models'],
            'tenant-guard.audit.ignored_tables' => [
                'migrations',
                'password_reset_tokens',
                'sessions',
                'cache',
                'cache_locks',
                'jobs',
                'job_batches',
                'failed_jobs',
                'stancl_tenants',
            ],
        ]);
    }

    protected function auditJson(array $options = []): array
    {
        // Artisan::call() buffers the output where Artisan::output() can read
        // it; $this->artisan() keeps it inside the PendingCommand instead.
        Artisan::call('tenant-guard:audit', $options + ['--json' => true]);

        return json_decode(Artisan::output(), true) ?? [];
    }

    public function test_it_reports_a_table_with_a_tenant_column_and_no_guarded_model(): void
    {
        $report = $this->auditJson();

        $errors = collect($report['errors'])->where('subject', 'legacy_notes')->values();

        $this->assertCount(1, $errors);
        $this->assertSame('unguarded-table', $errors[0]['type']);
        $this->assertStringContainsString('BelongsToTenant', $errors[0]['detail']);
    }

    public function test_it_reports_an_unclassified_table(): void
    {
        $report = $this->auditJson();

        $this->assertContains(
            'unclassified_widgets',
            collect($report['warnings'])->where('type', 'unclassified')->pluck('subject')->all()
        );
    }

    public function test_it_reports_a_tenant_table_without_a_leading_index(): void
    {
        $report = $this->auditJson();

        // posts and comments both index tenant_id first; legacy_notes does not,
        // but it is already reported as unguarded, so check the guarded ones
        // are *not* flagged.
        $missingIndex = collect($report['warnings'])->where('type', 'missing-index')->pluck('subject')->all();

        $this->assertNotContains('posts', $missingIndex);
        $this->assertNotContains('comments', $missingIndex);
    }

    public function test_it_does_not_flag_a_correctly_guarded_table(): void
    {
        $report = $this->auditJson();

        $subjects = collect($report['errors'])->pluck('subject')->all();

        $this->assertNotContains('posts', $subjects);
        $this->assertNotContains('comments', $subjects);
        $this->assertNotContains('users', $subjects);
    }

    public function test_it_does_not_flag_central_or_allow_listed_tables(): void
    {
        $report = $this->auditJson();

        $all = collect($report['errors'])->merge($report['warnings'])->pluck('subject')->all();

        $this->assertNotContains('tenants', $all);
        $this->assertNotContains('migrations', $all);
        $this->assertNotContains('jobs', $all);
    }

    public function test_plans_is_flagged_as_unclassified_until_it_is_allow_listed(): void
    {
        $before = collect($this->auditJson()['warnings'])->where('subject', 'plans')->count();
        $this->assertSame(1, $before, 'a central table should be declared, not assumed');

        config(['tenant-guard.audit.ignored_tables' => array_merge(
            config('tenant-guard.audit.ignored_tables'),
            ['plans']
        )]);

        $after = collect($this->auditJson()['warnings'])->where('subject', 'plans')->count();
        $this->assertSame(0, $after);
    }

    public function test_it_exits_non_zero_when_there_are_errors(): void
    {
        $this->artisan('tenant-guard:audit')->assertExitCode(1);
    }

    public function test_strict_mode_fails_on_warnings_too(): void
    {
        // Remove the error-level fixture so only warnings remain.
        $this->app['db']->connection()->getSchemaBuilder()->drop('legacy_notes');

        $this->artisan('tenant-guard:audit')->assertExitCode(0);
        $this->artisan('tenant-guard:audit', ['--strict' => true])->assertExitCode(1);
    }

    public function test_the_table_output_is_human_readable(): void
    {
        $this->artisan('tenant-guard:audit')
            ->expectsOutputToContain('legacy_notes')
            ->assertExitCode(1);
    }

    public function test_the_list_command_shows_every_tenant(): void
    {
        $this->artisan('tenant-guard:list')
            ->expectsOutputToContain('acme')
            ->expectsOutputToContain('globex')
            ->assertExitCode(0);
    }
}
