<?php

namespace Ifds\TenantGuard\Tests\Unit;

use Ifds\TenantGuard\Contracts\TenantContext;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Tenant;

class BootTest extends TestCase
{
    public function test_the_package_boots_and_the_workbench_schema_exists(): void
    {
        $this->assertInstanceOf(TenantContext::class, $this->tenancy());
        $this->assertSame(2, Tenant::query()->count());
        $this->assertTrue($this->app['db']->connection()->getSchemaBuilder()->hasTable('posts'));
    }
}
