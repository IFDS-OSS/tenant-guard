<?php

namespace Ifds\TenantGuard\Tests\Unit;

use Ifds\TenantGuard\Events\TenancyBypassed;
use Ifds\TenantGuard\Events\TenantForgotten;
use Ifds\TenantGuard\Events\TenantResolved;
use Ifds\TenantGuard\Exceptions\MissingTenantContextException;
use Ifds\TenantGuard\Exceptions\TenantNotFoundException;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class TenancyTest extends TestCase
{
    public function test_it_starts_with_no_context(): void
    {
        $this->assertFalse(TenantGuard::check());
        $this->assertNull(TenantGuard::current());
        $this->assertNull(TenantGuard::id());
    }

    public function test_it_sets_and_forgets_a_tenant(): void
    {
        TenantGuard::set($this->acme);

        $this->assertTrue(TenantGuard::check());
        $this->assertSame($this->acme->id, TenantGuard::id());

        TenantGuard::forget();

        $this->assertFalse(TenantGuard::check());
    }

    public function test_it_accepts_a_scalar_key(): void
    {
        TenantGuard::set($this->acme->id);

        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_it_throws_for_an_unknown_key(): void
    {
        $this->expectException(TenantNotFoundException::class);

        TenantGuard::set(999999);
    }

    public function test_require_throws_without_a_context(): void
    {
        $this->expectException(MissingTenantContextException::class);

        TenantGuard::require();
    }

    public function test_require_returns_the_tenant(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame($this->acme->id, TenantGuard::require()->getTenantKey());
        $this->assertSame($this->acme->id, TenantGuard::requireTenant()->getTenantKey());
    }

    public function test_run_for_restores_the_previous_context(): void
    {
        TenantGuard::set($this->acme);

        $inside = TenantGuard::runFor($this->globex, fn () => TenantGuard::id());

        $this->assertSame($this->globex->id, $inside);
        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_run_for_restores_the_context_even_when_the_callback_throws(): void
    {
        TenantGuard::set($this->acme);

        try {
            TenantGuard::runFor($this->globex, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_run_for_unwinds_to_no_context(): void
    {
        $this->assertFalse(TenantGuard::check());

        TenantGuard::runFor($this->acme, fn () => null);

        $this->assertFalse(TenantGuard::check());
    }

    public function test_run_for_nests(): void
    {
        $seen = [];

        TenantGuard::runFor($this->acme, function () use (&$seen) {
            $seen[] = TenantGuard::id();

            TenantGuard::runFor($this->globex, function () use (&$seen) {
                $seen[] = TenantGuard::id();
            });

            $seen[] = TenantGuard::id();
        });

        $this->assertSame([$this->acme->id, $this->globex->id, $this->acme->id], $seen);
        $this->assertFalse(TenantGuard::check());
    }

    public function test_run_without_suspends_and_restores_tenancy(): void
    {
        TenantGuard::set($this->acme);

        $this->assertFalse(TenantGuard::isBypassed());

        $inside = TenantGuard::runWithout(fn () => TenantGuard::isBypassed());

        $this->assertTrue($inside);
        $this->assertFalse(TenantGuard::isBypassed());
        $this->assertSame($this->acme->id, TenantGuard::id(), 'the tenant itself is untouched');
    }

    public function test_run_without_nests_correctly(): void
    {
        TenantGuard::runWithout(function () {
            TenantGuard::runWithout(fn () => $this->assertTrue(TenantGuard::isBypassed()));

            $this->assertTrue(TenantGuard::isBypassed(), 'inner unwind must not clear the outer bypass');
        });

        $this->assertFalse(TenantGuard::isBypassed());
    }

    public function test_run_without_restores_after_an_exception(): void
    {
        try {
            TenantGuard::runWithout(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(TenantGuard::isBypassed());
    }

    public function test_each_visits_every_tenant_and_restores_afterwards(): void
    {
        TenantGuard::set($this->acme);

        $visited = [];

        TenantGuard::each(function () use (&$visited) {
            $visited[] = TenantGuard::id();
        });

        $this->assertEqualsCanonicalizing([$this->acme->id, $this->globex->id], $visited);
        $this->assertSame($this->acme->id, TenantGuard::id());
    }

    public function test_it_dispatches_lifecycle_events(): void
    {
        Event::fake([TenantResolved::class, TenantForgotten::class, TenancyBypassed::class]);

        TenantGuard::set($this->acme);
        TenantGuard::runWithout(fn () => null);
        TenantGuard::forget();

        Event::assertDispatched(TenantResolved::class, fn (TenantResolved $e) => $e->tenant->getTenantKey() === $this->acme->id);
        Event::assertDispatched(TenancyBypassed::class);
        Event::assertDispatched(TenantForgotten::class);
    }

    public function test_setting_the_same_tenant_twice_is_a_no_op(): void
    {
        Event::fake([TenantResolved::class]);

        TenantGuard::set($this->acme);
        TenantGuard::set($this->acme->fresh());

        Event::assertDispatchedTimes(TenantResolved::class, 1);
    }

    public function test_the_tenant_is_resolvable_from_the_container(): void
    {
        TenantGuard::set($this->acme);

        $this->assertSame(
            $this->acme->id,
            $this->app->make(\Ifds\TenantGuard\Contracts\Tenant::class)->getTenantKey()
        );
    }
}
