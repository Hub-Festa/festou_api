<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\DomainTenantFinder;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainTenantFinderTest extends TestCase
{
    public function test_delegates_web_domain_resolution_to_resolver_service(): void
    {
        $tenant = Tenant::make([
            'name' => 'Mock Tenant',
            'subdomain' => 'mock-tenant',
        ]);

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock) use ($tenant) {
                $mock->shouldReceive('findTenantByDomain')
                    ->once()
                    ->with('tenant.example.test')
                    ->andReturn($tenant);
            })
        );

        /** @var DomainTenantFinder $finder */
        $finder = $this->app->make(DomainTenantFinder::class);

        $request = Request::create('https://tenant.example.test/environment', 'GET');
        $this->app->instance('request', $request);

        $result = $finder->findForRequest($request);

        $this->assertSame($tenant, $result);
    }

    public function test_does_not_treat_nested_landlord_host_as_a_tenant_subdomain(): void
    {
        $landlordHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($landlordHost) || trim($landlordHost) === '') {
            $landlordHost = trim(str_replace(['https://', 'http://'], '', (string) config('app.url')), '/');
        }

        $nestedHost = 'platform-test.extra.' . $landlordHost;
        $tenant = Tenant::make([
            'name' => 'Nested Host Fallback Tenant',
            'subdomain' => 'nested-host-fallback',
        ]);

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock) use ($nestedHost, $tenant): void {
                $mock->shouldReceive('findTenantByDomain')
                    ->once()
                    ->with($nestedHost)
                    ->andReturn($tenant);
            })
        );

        $this->instance(
            TenantAppDomainResolverService::class,
            $this->mock(TenantAppDomainResolverService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('findTenantByIdentifier')->never();
            })
        );

        $finder = $this->app->make(DomainTenantFinder::class);
        $request = Request::create('https://' . $nestedHost . '/api/v1/environment', 'GET');
        $this->app->instance('request', $request);

        $isSubdomain = new \ReflectionMethod(DomainTenantFinder::class, 'isRequestFromSubdomain');
        $isSubdomain->setAccessible(true);

        $this->assertFalse($isSubdomain->invoke($finder));
        $this->assertSame($tenant, $finder->findForRequest($request));
    }

    public function test_falls_back_to_web_domain_when_subdomain_resolution_returns_null(): void
    {
        $tenant = Tenant::make([
            'name' => 'Fallback Tenant',
            'subdomain' => 'fallback-tenant',
        ]);

        $landlordHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($landlordHost) || trim($landlordHost) === '') {
            $landlordHost = trim(str_replace(['https://', 'http://'], '', (string) config('app.url')), '/');
        }

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock) use ($tenant, $landlordHost): void {
                $mock->shouldReceive('findTenantByDomain')
                    ->once()
                    ->with("unknown-domain.{$landlordHost}")
                    ->andReturn($tenant);
            })
        );

        $this->instance(
            TenantAppDomainResolverService::class,
            $this->mock(TenantAppDomainResolverService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('findTenantByIdentifier')->never();
            })
        );

        /** @var DomainTenantFinder $finder */
        $finder = $this->app->make(DomainTenantFinder::class);

        $request = Request::create("https://unknown-domain.{$landlordHost}/api/v1/environment", 'GET');
        $request->headers->set('X-App-Domain', 'com.tenant.mobile');
        $this->app->instance('request', $request);

        $result = $finder->findForRequest($request);

        $this->assertSame($tenant, $result);
    }

    public function test_limits_app_domain_resolution_to_landlord_host(): void
    {
        $tenant = Tenant::make([
            'name' => 'Mobile Tenant',
            'subdomain' => 'mobile-tenant',
        ]);

        $this->instance(
            TenantAppDomainResolverService::class,
            $this->mock(TenantAppDomainResolverService::class, function (MockInterface $mock) use ($tenant) {
                $mock->shouldReceive('findTenantByIdentifier')
                    ->never();
            })
        );

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock) use ($tenant) {
                $mock->shouldReceive('findTenantByDomain')
                    ->once()
                    ->with('custom.example.test')
                    ->andReturn($tenant);
            })
        );

        /** @var DomainTenantFinder $finder */
        $finder = $this->app->make(DomainTenantFinder::class);

        $request = Request::create('https://custom.example.test/api/v1/environment', 'GET');
        $request->headers->set('X-App-Domain', 'com.tenant.mobile');
        $this->app->instance('request', $request);

        $result = $finder->findForRequest($request);

        $this->assertSame($tenant, $result);
    }
}
