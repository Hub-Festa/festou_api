<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class BrandingPublicWebMediaControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();
    }

    public function testDefaultImageRouteServesCanonicalPublicMedia(): void
    {
        Storage::fake('public');

        $mediaService = $this->app->make(BrandingPublicWebMediaService::class);
        $mediaService->storeDefaultImage(
            'https://tenant-branding.test',
            $this->tenant,
            UploadedFile::fake()->image('default-image.png')
        );

        $response = $this->get(sprintf(
            'http://tenant-branding.test/api/v1/media/branding-public-web/%s/default_image',
            $this->tenant->_id
        ));

        $response->assertOk();
        $response->assertHeader('content-type', 'image/png');
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Branding', 'subdomain' => 'tenant-branding'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-branding.test']
        );

        $service->initialize($payload);
    }
}
