<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Shared\PushHandler\Models\Tenants\TenantPushSettings;
use Tests\TestCaseAuthenticated;

class ApiV1EnvironmentApiTest extends TestCaseAuthenticated
{
    private Tenant $tenant;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()
            ->where('slug', $this->landlord->tenant_primary->slug)
            ->firstOrFail();
        $this->tenant->domains()->withTrashed()->get()->each->forceDelete();
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-beta.test',
        ]);
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.beta',
        ]);

        $this->baseUrl = "http://{$this->tenant->subdomain}.{$this->host}/api/v1/environment";
    }

    public function testEnvironmentApiReturnsTenantPayload(): void
    {
        $response = $this->withHeaders([
            'X-App-Domain' => 'com.tenant.beta',
        ])->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'type',
            'tenant_id',
            'name',
            'subdomain',
            'main_domain',
            'domains',
            'app_domains',
            'theme_data_settings',
        ]);
        $response->assertJsonPath('type', 'tenant');
        $response->assertJsonPath('main_domain', 'https://tenant-beta.test');
        $response->assertJsonPath('theme_data_settings.brightness_default', 'light');
        $response->assertJsonPath('theme_data_settings.primary_seed_color', '#FFFFFF');
        $response->assertJsonPath('theme_data_settings.secondary_seed_color', '#999999');
    }

    public function testEnvironmentApiReturnsNormalizedPublicWebMetadata(): void
    {
        Storage::fake('public');

        $baseHost = "{$this->tenant->subdomain}.{$this->host}";
        $mediaService = $this->app->make(BrandingPublicWebMediaService::class);
        $canonical = $mediaService->storeDefaultImage(
            "https://{$baseHost}",
            $this->tenant,
            UploadedFile::fake()->image('default-image.png')
        );
        $version = (string) parse_url($canonical, PHP_URL_QUERY);
        parse_str($version, $parameters);
        $resolvedVersion = (string) ($parameters['v'] ?? '');

        $this->tenant->branding_data = [
            'public_web_metadata' => [
                'default_image' => sprintf(
                    'https://%s/storage/tenants/%s/public-web/default-image.png?v=%s',
                    $baseHost,
                    $this->tenant->slug,
                    $resolvedVersion
                ),
            ],
        ];
        $this->tenant->save();

        $response = $this->withHeaders([
            'X-App-Domain' => 'com.tenant.beta',
        ])->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('public_web_metadata.default_image', $canonical);
    }

    public function testEnvironmentApiFallsBackToSubdomainWhenNoDomains(): void
    {
        $this->tenant->domains()->delete();
        $this->tenant->domains = [];
        $this->tenant->save();

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'main_domain',
            "https://{$this->tenant->subdomain}.{$this->host}"
        );
    }

    public function testEnvironmentApiIgnoresTypedAppDomainsForMainDomain(): void
    {
        $this->tenant->domains()->withTrashed()->get()->each->forceDelete();
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.beta',
        ]);
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-beta.test',
        ]);

        $response = $this->withHeaders([
            'X-App-Domain' => 'com.tenant.beta',
        ])->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('main_domain', 'https://tenant-beta.test');
    }

    public function testEnvironmentApiNormalizesFlatTelemetryMap(): void
    {
        $this->tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create([
            'max_ttl_days' => 30,
            'push_message_types' => [
                [
                    'key' => 'invite_received',
                    'label' => 'Invite Received',
                ],
            ],
            'telemetry' => [
                'mixpanel_token' => 'flat-map-token',
                'enabled_events' => ['invite_received'],
            ],
        ]);

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('telemetry.0.type', 'mixpanel');
        $response->assertJsonPath('telemetry.0.token', 'flat-map-token');
        $response->assertJsonPath('telemetry.0.events.0', 'invite_received');
    }

}
