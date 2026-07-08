<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private LandlordUser $operator;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->update(['app_domains' => []]);
        $this->tenant->domains()->withTrashed()->whereIn('type', [
            Tenant::DOMAIN_TYPE_APP_ANDROID,
            Tenant::DOMAIN_TYPE_APP_IOS,
        ])->get()->each->forceDelete();
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.theta',
        ]);
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
            'path' => 'com.tenant.theta.ios',
        ]);

        $this->tenant->makeCurrent();
        $this->operator = LandlordUser::query()->firstOrFail();
        $this->baseUrl = "http://{$this->tenant->subdomain}.{$this->host}/admin/api/v1/appdomains";
    }

    public function testIndexRequiresReadAbility(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-domains:update'], 'sanctum');

        $response = $this->getJson($this->baseUrl);

        $response->assertForbidden();
    }

    public function testIndexReturnsTypedTenantAppDomains(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-domains:read'], 'sanctum');

        $response = $this->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJsonPath('app_domains.android', 'com.tenant.theta');
        $response->assertJsonPath('app_domains.ios', 'com.tenant.theta.ios');
    }

    public function testStoreUpsertsTypedDomain(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-domains:update'], 'sanctum');

        $response = $this->postJson($this->baseUrl, [
            'platform' => 'android',
            'identifier' => 'com.tenant.theta.mobile',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier saved successfully.');
        $response->assertJsonPath('app_domains.android', 'com.tenant.theta.mobile');
    }

    public function testDestroyRemovesTypedDomain(): void
    {
        Sanctum::actingAs($this->operator, ['tenant-domains:update'], 'sanctum');

        $response = $this->deleteJson($this->baseUrl, [
            'platform' => 'android',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier removed successfully.');
        $response->assertJsonPath('app_domains.android', null);
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
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
