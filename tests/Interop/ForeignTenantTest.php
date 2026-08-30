<?php

namespace Ifds\TenantGuard\Tests\Interop;

use Ifds\TenantGuard\Concerns\GuardedByTenant;
use Ifds\TenantGuard\Contracts\Tenant as TenantContract;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Interop\ForeignTenant;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Interop\CustomTenant;
use Workbench\App\Models\Post;

/**
 * Compatibility with everything else: any tenant model at all, and the trait
 * name collision that appears when another tenancy package is also installed.
 */
class ForeignTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 2);
        $this->seedPosts($this->globex, 4);

        TenantGuard::forget();
    }

    public function test_a_model_that_implements_nothing_can_still_be_the_tenant(): void
    {
        $custom = CustomTenant::find($this->acme->id);

        $this->assertNotInstanceOf(TenantContract::class, $custom);

        TenantGuard::set($custom);

        $this->assertSame($this->acme->id, TenantGuard::id());
        $this->assertSame(2, Post::count());
    }

    public function test_a_foreign_model_keeps_its_own_methods_through_the_wrapper(): void
    {
        TenantGuard::set(CustomTenant::find($this->globex->id));

        $current = TenantGuard::current();

        $this->assertInstanceOf(ForeignTenant::class, $current);
        $this->assertSame('org-'.$this->globex->id, $current->organisationRef());
        $this->assertSame('globex', $current->slug);
    }

    public function test_an_explicit_key_name_can_be_given(): void
    {
        $wrapped = ForeignTenant::wrap(CustomTenant::find($this->acme->id), 'slug');

        $this->assertSame('acme', $wrapped->getTenantKey());
        $this->assertSame('slug', $wrapped->getTenantKeyName());
    }

    public function test_wrapping_a_model_that_already_implements_the_contract_is_a_no_op(): void
    {
        $wrapped = ForeignTenant::wrap($this->acme);

        $this->assertSame($this->acme, $wrapped);
    }

    public function test_a_model_with_no_readable_key_is_rejected_clearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not read a tenant key');

        (new ForeignTenant(new \stdClass))->getTenantKey();
    }

    public function test_run_for_accepts_a_foreign_model(): void
    {
        $count = TenantGuard::runFor(CustomTenant::find($this->globex->id), fn () => Post::count());

        $this->assertSame(4, $count);
        $this->assertFalse(TenantGuard::check());
    }

    public function test_the_alias_trait_avoids_a_name_collision(): void
    {
        // GuardedByTenant exists so a model can also import another package's
        // trait literally named BelongsToTenant.
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            use GuardedByTenant;

            protected $table = 'posts';

            protected $guarded = [];
        };

        TenantGuard::set($this->acme);

        $this->assertSame('tenant_id', $model->getTenantColumn());
        $this->assertSame(2, $model->newQuery()->count());
        $this->assertStringContainsString('"posts"."tenant_id" =', $model->newQuery()->toSql());
    }

    public function test_switching_between_a_native_and_a_foreign_tenant_is_seamless(): void
    {
        TenantGuard::set($this->acme);
        $this->assertSame(2, Post::count());

        TenantGuard::set(CustomTenant::find($this->globex->id));
        $this->assertSame(4, Post::count());

        TenantGuard::set($this->acme);
        $this->assertSame(2, Post::count());
    }
}
