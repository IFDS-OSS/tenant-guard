<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Post;

/**
 * End-to-end: a real HTTP request through the workbench application.
 *
 * The controller contains no where() clause at all - if these pass, the
 * isolation is coming entirely from the middleware plus the global scope.
 */
class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPosts($this->acme, 3);
        $this->seedPosts($this->globex, 5);

        $this->actingWithoutTenant();
    }

    public function test_a_subdomain_request_sees_only_that_tenants_posts(): void
    {
        $this->get('http://acme.example.test/posts')
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id)
            ->assertJsonPath('count', 3);
    }

    public function test_a_different_subdomain_sees_different_posts(): void
    {
        $this->get('http://globex.example.test/posts')
            ->assertOk()
            ->assertJsonPath('tenant', $this->globex->id)
            ->assertJsonPath('count', 5);
    }

    public function test_a_custom_domain_resolves(): void
    {
        $this->get('http://globex.example.com/posts')
            ->assertOk()
            ->assertJsonPath('tenant', $this->globex->id);
    }

    public function test_a_header_resolves(): void
    {
        $this->withHeader('X-Tenant', 'acme')
            ->get('http://example.test/posts')
            ->assertOk()
            ->assertJsonPath('tenant', $this->acme->id);
    }

    public function test_a_path_segment_resolves(): void
    {
        $this->get('http://example.test/t/globex/posts')
            ->assertOk()
            ->assertJsonPath('tenant', $this->globex->id);
    }

    public function test_an_unknown_tenant_gets_a_404_when_resolution_is_required(): void
    {
        config(['tenant-guard.resolution.required' => true]);

        $this->get('http://nobody.example.test/posts')->assertNotFound();
    }

    public function test_an_unresolved_request_fails_closed_rather_than_leaking(): void
    {
        // Resolution is not required, so the request reaches the controller with
        // no tenant. The scope must refuse rather than return all 8 posts.
        config(['tenant-guard.resolution.required' => false]);

        $this->withoutExceptionHandling();

        $this->expectException(\Ifds\TenantGuard\Exceptions\MissingTenantContextException::class);

        $this->get('http://nobody.example.test/posts');
    }

    public function test_the_require_tenant_middleware_rejects_a_central_request(): void
    {
        $this->get('http://example.test/tenant-only')->assertNotFound();

        $this->get('http://acme.example.test/tenant-only')->assertOk();
    }

    public function test_central_routes_keep_working_without_a_tenant(): void
    {
        \Workbench\App\Models\Plan::factory()->count(2)->create();

        $this->get('http://example.test/plans')
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_writes_through_http_are_stamped_with_the_tenant(): void
    {
        $response = $this->post('http://acme.example.test/posts', [
            'title' => 'Written over HTTP',
            'slug' => 'written-over-http',
        ]);

        $response->assertCreated()->assertJsonPath('tenant', $this->acme->id);

        $this->assertSame(4, TenantGuard::runFor($this->acme, fn () => Post::count()));
        $this->assertSame(5, TenantGuard::runFor($this->globex, fn () => Post::count()));
    }

    public function test_the_context_does_not_survive_the_response(): void
    {
        $this->get('http://acme.example.test/posts')->assertOk();

        $this->assertFalse(
            TenantGuard::check(),
            'IdentifyTenant::terminate() must clear the context for the next request'
        );
    }

    public function test_two_requests_in_a_row_do_not_bleed(): void
    {
        $this->get('http://acme.example.test/posts')->assertJsonPath('count', 3);
        $this->get('http://globex.example.test/posts')->assertJsonPath('count', 5);
        $this->get('http://acme.example.test/posts')->assertJsonPath('count', 3);
    }
}
