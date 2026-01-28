<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\TenantSettings;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class StaticAssetsControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private string $baseAdminAssets;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->update([
            'app_domains' => ['tenant-zeta.test'],
        ]);
        $tenant->makeCurrent();

        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'poi_time_window_hours' => [
                    'past' => 6,
                    'future' => 720,
                ],
            ],
        ]);

        $this->baseAdminAssets = "{$this->base_tenant_api_admin}static_assets";
        $this->headers = array_merge($this->getHeaders(), [
            'X-App-Domain' => 'tenant-zeta.test',
        ]);
    }

    public function testStaticAssetCrudCreatesPoiProjection(): void
    {
        $payload = $this->makeAssetPayload();

        $response = $this->withHeaders($this->headers)->postJson($this->baseAdminAssets, $payload);
        $response->assertStatus(201);

        $assetId = $response->json('data.id');
        $this->assertNotNull($assetId);

        $poi = MapPoi::query()->where('ref_type', 'static')->where('ref_id', $assetId)->first();
        $this->assertNotNull($poi);

        $update = $this->withHeaders($this->headers)->patchJson("{$this->baseAdminAssets}/{$assetId}", [
            'name' => 'Updated Asset',
            'is_active' => false,
        ]);
        $update->assertStatus(200);

        $poi = MapPoi::query()->where('ref_type', 'static')->where('ref_id', $assetId)->first();
        $this->assertNotNull($poi);
        $this->assertFalse((bool) $poi->is_active);

        $delete = $this->withHeaders($this->headers)->deleteJson("{$this->baseAdminAssets}/{$assetId}");
        $delete->assertStatus(200);

        $this->assertNull(MapPoi::query()->where('ref_type', 'static')->where('ref_id', $assetId)->first());

        $restore = $this->withHeaders($this->headers)->postJson("{$this->baseAdminAssets}/{$assetId}/restore");
        $restore->assertStatus(200);

        $poi = MapPoi::query()->where('ref_type', 'static')->where('ref_id', $assetId)->first();
        $this->assertNotNull($poi);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAssetPayload(): array
    {
        return [
            'name' => 'Praia Azul',
            'description' => 'Praia tranquila',
            'category' => 'beach',
            'tags' => ['praia'],
            'taxonomy_terms' => [
                ['type' => 'vibe', 'value' => 'calma'],
            ],
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
            'priority' => 20,
            'is_active' => true,
        ];
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
