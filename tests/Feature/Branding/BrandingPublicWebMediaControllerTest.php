<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function testPublicBrandingAssetRoutesGenerateNeutralMediaWhenStoredMediaIsMissing(): void
    {
        Storage::fake('public');

        $this->tenant->branding_data = [];
        $this->tenant->save();
        Landlord::singleton()->update(['branding_data' => []]);

        foreach ([
            '/favicon.ico',
            '/logo-light.png',
            '/logo-dark.png',
            '/icon-light.png',
            '/icon-dark.png',
            '/icon/icon-192x192.png',
            '/icon/icon-512x512.png',
            '/icon/icon-maskable-512x512.png',
        ] as $route) {
            $response = $this->get("http://tenant-branding.test{$route}");

            $this->assertSame(200, $response->getStatusCode(), $route.' should resolve successfully.');
            $response->assertHeader('content-type', 'image/png');
            $this->assertNotInstanceOf(
                BinaryFileResponse::class,
                $response->baseResponse,
                $route.' must be generated at runtime rather than served from a web-shell asset.'
            );
            $this->assertNotEmpty($response->getContent(), $route.' should contain generated image bytes.');
        }
    }

    public function testFaviconResolvesHostScopedStoredMediaBeforeTheGeneratedFallback(): void
    {
        Storage::fake('public');
        config()->set('app.url', 'https://landlord-branding.test');

        $landlord = Landlord::singleton();
        $landlordPath = 'landlord/logos/favicon.ico';
        $tenantPath = "tenants/{$this->tenant->slug}/logos/favicon.ico";
        $faviconContents = (string) file_get_contents(base_path('tests/Assets/landlord.ico'));

        Storage::disk('public')->put($landlordPath, $faviconContents);
        Storage::disk('public')->put($tenantPath, $faviconContents);
        $this->setFaviconUri($landlord, "/storage/{$landlordPath}");
        $this->setFaviconUri($this->tenant, "/storage/{$tenantPath}");

        $landlordResponse = $this->get('http://landlord-branding.test/favicon.ico');
        $tenantResponse = $this->get('http://tenant-branding.test/favicon.ico');

        $this->assertServesStoredFile($landlordResponse, $landlordPath);
        $this->assertServesStoredFile($tenantResponse, $tenantPath);
    }

    private function setFaviconUri(Landlord|Tenant $brandable, string $uri): void
    {
        $branding = is_array($brandable->branding_data) ? $brandable->branding_data : [];
        $logoSettings = is_array($branding['logo_settings'] ?? null)
            ? $branding['logo_settings']
            : [];
        $logoSettings['favicon_uri'] = $uri;
        $branding['logo_settings'] = $logoSettings;
        $brandable->branding_data = $branding;
        $brandable->save();
    }

    private function assertServesStoredFile($response, string $expectedPath): void
    {
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            Storage::disk('public')->path($expectedPath),
            $response->baseResponse->getFile()->getPathname(),
        );
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
