<?php

declare(strict_types=1);

namespace Tests\Unit\Branding;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class BrandingPublicWebMediaServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private BrandingPublicWebMediaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(BrandingPublicWebMediaService::class);
    }

    public function testNormalizePublicUrlConvertsLegacyStorageUrlsToCanonicalUrls(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $canonical = $this->service->storeDefaultImage(
            'https://tenant-branding.test',
            $tenant,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');

        $legacy = sprintf(
            'https://tenant-branding.test/storage/tenants/%s/public-web/default-image.png?v=%s',
            $tenant->slug,
            $resolvedVersion
        );

        $this->assertSame(
            $canonical,
            $this->service->normalizePublicUrl('https://tenant-branding.test', $tenant, $legacy)
        );
    }

    public function testMaterializeBrandingDataNormalizesNestedDefaultImage(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $canonical = $this->service->storeDefaultImage(
            'https://tenant-branding.test',
            $tenant,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');
        $legacy = sprintf(
            'https://tenant-branding.test/storage/tenants/%s/public-web/default-image.png?v=%s',
            $tenant->slug,
            $resolvedVersion
        );

        $materialized = $this->service->materializeBrandingData(
            'https://tenant-branding.test',
            $tenant,
            [
                'public_web_metadata' => [
                    'default_image' => $legacy,
                ],
            ]
        );

        $this->assertSame($canonical, $materialized['public_web_metadata']['default_image']);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Beta', 'subdomain' => 'tenant-beta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-beta.test']
        );

        $service->initialize($payload);
    }
}
