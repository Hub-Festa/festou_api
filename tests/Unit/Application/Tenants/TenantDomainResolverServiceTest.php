<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class TenantDomainResolverServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private TenantDomainResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
        $this->service = $this->app->make(TenantDomainResolverService::class);
    }

    protected function tearDown(): void
    {
        $this->refreshLandlordAndTenantDatabases();

        parent::tearDown();
    }

    public function test_finds_tenant_via_inline_domains_regardless_of_case(): void
    {
        $tenant = Tenant::create([
            'name' => 'Inline Domain',
            'subdomain' => 'inline-domain',
            'domains' => ['ExampleTenant.COM'],
        ]);

        $resolved = $this->service->findTenantByDomain('exampletenant.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $tenant->_id, (string) $resolved->_id);
    }

    public function test_falls_back_to_domains_collection_when_inline_domain_missing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Collection Tenant',
            'subdomain' => 'collection-tenant',
            'domains' => [],
        ]);

        $domain = new Domains([
            'path' => 'TenantCollection.COM',
            'type' => 'web',
        ]);
        $domain->tenant()->associate($tenant);
        $domain->save();

        $resolved = $this->service->findTenantByDomain('tenantcollection.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $tenant->_id, (string) $resolved->_id);
    }

    public function test_prefers_typed_web_domain_collection_before_legacy_inline_domains_array(): void
    {
        $legacyTenant = Tenant::create([
            'name' => 'Legacy Inline Tenant',
            'subdomain' => 'legacy-inline',
            'domains' => ['shared-domain.com'],
        ]);

        $canonicalTenant = Tenant::create([
            'name' => 'Canonical Collection Tenant',
            'subdomain' => 'canonical-collection',
            'domains' => [],
        ]);

        $domain = new Domains([
            'path' => 'Shared-Domain.COM',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        $domain->tenant()->associate($canonicalTenant);
        $domain->save();

        $resolved = $this->service->findTenantByDomain('shared-domain.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $canonicalTenant->_id, (string) $resolved->_id);
        $this->assertNotSame((string) $legacyTenant->_id, (string) $resolved->_id);
    }

    public function test_ignores_non_web_domain_collection_records_before_legacy_fallback(): void
    {
        $legacyTenant = Tenant::create([
            'name' => 'Legacy Inline Tenant',
            'subdomain' => 'legacy-inline-non-web',
            'domains' => ['mobile-only-domain.com'],
        ]);

        $mobileTenant = Tenant::create([
            'name' => 'Mobile Domain Tenant',
            'subdomain' => 'mobile-domain',
            'domains' => [],
        ]);

        $domain = new Domains([
            'path' => 'Mobile-Only-Domain.COM',
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
        ]);
        $domain->tenant()->associate($mobileTenant);
        $domain->save();

        $resolved = $this->service->findTenantByDomain('mobile-only-domain.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $legacyTenant->_id, (string) $resolved->_id);
        $this->assertNotSame((string) $mobileTenant->_id, (string) $resolved->_id);
    }

    public function test_returns_null_when_no_typed_web_or_legacy_domain_matches(): void
    {
        $tenant = Tenant::create([
            'name' => 'Mobile Only Tenant',
            'subdomain' => 'mobile-only',
            'domains' => [],
        ]);

        $domain = new Domains([
            'path' => 'No-Web-Match.COM',
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
        ]);
        $domain->tenant()->associate($tenant);
        $domain->save();

        $this->assertNull($this->service->findTenantByDomain('no-web-match.com'));
    }
}
