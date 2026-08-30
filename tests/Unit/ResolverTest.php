<?php

namespace Ifds\TenantGuard\Tests\Unit;

use Illuminate\Http\Request;
use Ifds\TenantGuard\Contracts\TenantResolver;
use Ifds\TenantGuard\Resolvers\ChainResolver;
use Ifds\TenantGuard\Resolvers\ClosureResolver;
use Ifds\TenantGuard\Resolvers\DomainResolver;
use Ifds\TenantGuard\Resolvers\HeaderResolver;
use Ifds\TenantGuard\Resolvers\PathResolver;
use Ifds\TenantGuard\Resolvers\SubdomainResolver;
use Ifds\TenantGuard\Resolvers\UserResolver;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\User;

class ResolverTest extends TestCase
{
    protected function request(string $uri = 'http://acme.example.test/posts', array $headers = []): Request
    {
        $request = Request::create($uri);

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }

    public function test_subdomain_resolver_matches_the_slug(): void
    {
        $resolver = $this->app->make(SubdomainResolver::class);

        $tenant = $resolver->resolve($this->request('http://acme.example.test/posts'));

        $this->assertNotNull($tenant);
        $this->assertSame($this->acme->id, $tenant->getTenantKey());
    }

    public function test_subdomain_resolver_ignores_the_central_domain(): void
    {
        $resolver = $this->app->make(SubdomainResolver::class);

        $this->assertNull($resolver->resolve($this->request('http://example.test/posts')));
    }

    public function test_subdomain_resolver_ignores_configured_subdomains(): void
    {
        config(['tenant-guard.resolution.ignored_subdomains' => ['acme']]);

        $resolver = $this->app->make(SubdomainResolver::class);

        $this->assertNull($resolver->resolve($this->request('http://acme.example.test/posts')));
    }

    public function test_subdomain_extraction_without_central_domains(): void
    {
        config(['tenant-guard.resolution.central_domains' => []]);

        $resolver = $this->app->make(SubdomainResolver::class);

        $this->assertSame('acme', $resolver->subdomainFor('acme.example.test'));
        $this->assertNull($resolver->subdomainFor('example.test'));
        $this->assertNull($resolver->subdomainFor('localhost'));
    }

    public function test_domain_resolver_matches_a_custom_domain(): void
    {
        $resolver = $this->app->make(DomainResolver::class);

        $tenant = $resolver->resolve($this->request('http://globex.example.com/posts'));

        $this->assertNotNull($tenant);
        $this->assertSame($this->globex->id, $tenant->getTenantKey());
    }

    public function test_header_resolver_accepts_a_slug_or_a_key(): void
    {
        $resolver = $this->app->make(HeaderResolver::class);

        $this->assertSame(
            $this->acme->id,
            $resolver->resolve($this->request('http://example.test/', ['X-Tenant' => 'acme']))->getTenantKey()
        );

        $this->assertSame(
            $this->globex->id,
            $resolver->resolve($this->request('http://example.test/', ['X-Tenant' => (string) $this->globex->id]))->getTenantKey()
        );
    }

    public function test_header_resolver_returns_null_for_an_unknown_value(): void
    {
        $resolver = $this->app->make(HeaderResolver::class);

        $this->assertNull($resolver->resolve($this->request('http://example.test/', ['X-Tenant' => 'nope'])));
    }

    public function test_path_resolver_reads_a_configured_segment(): void
    {
        config(['tenant-guard.resolution.path_segment' => 2]);

        $resolver = $this->app->make(PathResolver::class);

        $tenant = $resolver->resolve($this->request('http://example.test/t/globex/posts'));

        $this->assertNotNull($tenant);
        $this->assertSame($this->globex->id, $tenant->getTenantKey());
    }

    public function test_user_resolver_reads_the_authenticated_user(): void
    {
        $user = $this->withinTenant($this->acme, fn () => User::factory()->create());

        $request = $this->request('http://example.test/');
        $request->setUserResolver(fn () => $user);

        $tenant = $this->app->make(UserResolver::class)->resolve($request);

        $this->assertNotNull($tenant);
        $this->assertSame($this->acme->id, $tenant->getTenantKey());
    }

    public function test_user_resolver_returns_null_for_a_guest(): void
    {
        $this->assertNull($this->app->make(UserResolver::class)->resolve($this->request('http://example.test/')));
    }

    public function test_the_chain_returns_the_first_match_and_reports_it(): void
    {
        $chain = new ChainResolver($this->app, [
            HeaderResolver::class,     // will miss
            SubdomainResolver::class,  // will hit
            DomainResolver::class,
        ]);

        $tenant = $chain->resolve($this->request('http://acme.example.test/posts'));

        $this->assertSame($this->acme->id, $tenant->getTenantKey());
        $this->assertSame(SubdomainResolver::class, $chain->matchedResolver());
    }

    public function test_the_chain_returns_null_when_nothing_matches(): void
    {
        $chain = new ChainResolver($this->app, [HeaderResolver::class]);

        $this->assertNull($chain->resolve($this->request('http://example.test/')));
        $this->assertNull($chain->matchedResolver());
    }

    public function test_a_closure_resolver_can_be_prepended(): void
    {
        $chain = $this->app->make(TenantResolver::class);

        $this->assertInstanceOf(ChainResolver::class, $chain);

        $chain->prepend(new ClosureResolver(fn () => $this->globex));

        $tenant = $chain->resolve($this->request('http://acme.example.test/posts'));

        $this->assertSame($this->globex->id, $tenant->getTenantKey(), 'the prepended resolver should win');
    }
}
