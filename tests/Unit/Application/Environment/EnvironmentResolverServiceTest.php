<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Environment;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Environment\EnvironmentResolverService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
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

    public function testResolveReturnsTenantEnvironmentWhenAvailable(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->domains()->withTrashed()->get()->each->forceDelete();
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.beta',
        ]);
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-beta.test',
        ]);
        $tenant->makeCurrent();
        $mediaService = $this->app->make(BrandingPublicWebMediaService::class);
        $canonical = $mediaService->storeDefaultImage(
            'https://tenant-beta.test',
            $tenant,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');
        $tenant->branding_data = [
            'public_web_metadata' => [
                'default_image' => sprintf(
                    'https://tenant-beta.test/storage/tenants/%s/public-web/default-image.png?v=%s',
                    $tenant->slug,
                    $resolvedVersion
                ),
            ],
        ];
        $tenant->save();

        $result = $this->service->resolve([
            'app_domain' => 'com.tenant.beta',
            'request_root' => 'https://tenant-beta.test',
            'request_host' => 'tenant-beta.test',
        ]);

        $this->assertSame('tenant', $result['type']);
        $this->assertSame($tenant->name, $result['name']);
        $this->assertSame('https://tenant-beta.test', $result['main_domain']);
        $this->assertSame(['com.tenant.beta'], $result['app_domains']);
        $this->assertSame(parse_url($canonical, PHP_URL_PATH), parse_url($result['public_web_metadata']['default_image'], PHP_URL_PATH));
        $this->assertSame(parse_url($canonical, PHP_URL_QUERY), parse_url($result['public_web_metadata']['default_image'], PHP_URL_QUERY));
    }

    public function testResolveFallsBackToLandlordEnvironment(): void
    {
        Tenant::forgetCurrent();

        $result = $this->service->resolve(['request_root' => 'http://landlord.test']);

        $this->assertSame('landlord', $result['type']);
        $this->assertSame('https://landlord.test', $result['main_domain']);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Beta', 'subdomain' => 'tenant-beta', 'app_domains' => ['tenant-beta.test']],
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
