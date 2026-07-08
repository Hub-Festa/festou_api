<?php

declare(strict_types=1);

namespace Tests\Unit\Environment;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Environment\EnvironmentResolverService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class EnvironmentResolverServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private EnvironmentResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(EnvironmentResolverService::class);
    }

    public function testResolveReturnsNormalizedPublicWebMetadataForLandlordEnvironment(): void
    {
        Storage::fake('public');

        $landlord = Landlord::singleton();
        $mediaService = $this->app->make(BrandingPublicWebMediaService::class);
        $canonical = $mediaService->storeDefaultImage(
            'https://landlord-shell.test',
            $landlord,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');
        $legacy = sprintf(
            'https://landlord-shell.test/storage/tenants/landlord/public-web/default-image.png?v=%s',
            $resolvedVersion
        );

        $landlord->branding_data = [
            'public_web_metadata' => [
                'default_image' => $legacy,
            ],
        ];
        $landlord->save();

        Tenant::forgetCurrent();

        $result = $this->service->resolve([
            'request_root' => 'https://landlord-shell.test',
        ]);

        $this->assertSame('landlord', $result['type']);
        $this->assertSame($canonical, $result['public_web_metadata']['default_image']);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Delta', 'subdomain' => 'tenant-delta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-delta.test']
        );

        $service->initialize($payload);
    }
}
