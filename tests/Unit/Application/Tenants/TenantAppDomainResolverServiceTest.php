<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainResolverServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantAppDomainResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();
        $this->service = $this->app->make(TenantAppDomainResolverService::class);
    }

    public function testFindTenantByIdentifierResolvesTypedAndroidDomain(): void
    {
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.example.tenant.android',
        ]);

        $resolved = $this->service->findTenantByIdentifier('com.example.tenant.android');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    public function testFindTenantByIdentifierResolvesTypedIosDomain(): void
    {
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
            'path' => 'com.example.tenant.ios',
        ]);

        $resolved = $this->service->findTenantByIdentifier('com.example.tenant.ios');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    public function testFindTenantByIdentifierFallsBackToLegacyAppDomains(): void
    {
        $this->tenant->update([
            'app_domains' => ['legacy.example.tenant.app'],
        ]);

        $resolved = $this->service->findTenantByIdentifier('legacy.example.tenant.app');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Theta', 'subdomain' => 'tenant-theta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'fixture-password-placeholder'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
