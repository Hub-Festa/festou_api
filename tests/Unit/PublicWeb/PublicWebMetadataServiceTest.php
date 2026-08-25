<?php

declare(strict_types=1);

namespace Tests\Unit\PublicWeb;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\PublicWeb\PublicWebMetadataService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PublicWebMetadataServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private PublicWebMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(PublicWebMetadataService::class);
    }

    public function testDefaultMetadataUsesNormalizedPublicWebImageWhenPresent(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $mediaService = $this->app->make(BrandingPublicWebMediaService::class);
        $canonical = $mediaService->storeDefaultImage(
            'https://tenant-shell.test',
            $tenant,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');
        $legacy = sprintf(
            'https://tenant-shell.test/storage/tenants/%s/public-web/default-image.png?v=%s',
            $tenant->slug,
            $resolvedVersion
        );

        $tenant->branding_data = [
            'public_web_metadata' => [
                'default_image' => $legacy,
            ],
        ];
        $tenant->save();

        $this->withServerVariables([
            'HTTP_HOST' => 'tenant-shell.test',
        ]);

        $metadata = $this->service->defaultMetadata('/example');

        $this->assertSame(parse_url($canonical, PHP_URL_PATH), parse_url($metadata['image'], PHP_URL_PATH));
        $this->assertSame(parse_url($canonical, PHP_URL_QUERY), parse_url($metadata['image'], PHP_URL_QUERY));
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Shell', 'subdomain' => 'tenant-shell'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-shell.test']
        );

        $service->initialize($payload);
    }
}
