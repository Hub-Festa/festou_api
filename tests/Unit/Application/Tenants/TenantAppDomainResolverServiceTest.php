<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Domains;
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
        Domains::query()
            ->where('tenant_id', (string) $this->tenant->getKey())
            ->whereIn('type', [
                Tenant::DOMAIN_TYPE_APP_ANDROID,
                Tenant::DOMAIN_TYPE_APP_IOS,
            ])
            ->delete();
        $this->tenant->update([
            'app_domains' => [],
        ]);

        $this->service = $this->app->make(TenantAppDomainResolverService::class);
    }

    public function test_find_tenant_by_identifier_resolves_typed_android_domain(): void
    {
        $this->createAppIdentifier(Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.festou.app');

        $resolved = $this->service->findTenantByIdentifier('com.festou.app');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    public function test_find_tenant_by_identifier_resolves_typed_ios_domain(): void
    {
        $this->createAppIdentifier(Tenant::DOMAIN_TYPE_APP_IOS, 'com.festou.ios');

        $resolved = $this->service->findTenantByIdentifier('com.festou.ios');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    public function test_find_tenant_by_identifier_falls_back_to_legacy_app_domains(): void
    {
        $this->tenant->update([
            'app_domains' => ['legacy.festou.app'],
        ]);

        $resolved = $this->service->findTenantByIdentifier('legacy.festou.app');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $this->tenant->getKey(), (string) $resolved?->getKey());
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Theta', 'subdomain' => 'tenant-theta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
        );

        $service->initialize($payload);
    }

    private function createAppIdentifier(string $type, string $path): void
    {
        $record = new Domains([
            'type' => $type,
            'path' => $path,
        ]);
        $record->tenant()->associate($this->tenant);
        $record->save();
    }
}
