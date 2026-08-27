<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Environment\TenantEnvironmentPayloadFactory;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
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
        $this->tenant->makeCurrent();
        TenantEnvironmentSnapshot::query()->delete();

        $this->baseUrl = "http://{$this->tenant->subdomain}.{$this->host}/api/v1/environment";
    }

    public function test_environment_api_returns_tenant_payload(): void
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

    public function test_environment_api_returns_normalized_public_web_metadata(): void
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

    public function test_environment_api_falls_back_to_subdomain_when_no_domains(): void
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

    public function test_environment_api_ignores_typed_app_domains_for_main_domain(): void
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

    public function test_environment_api_normalizes_flat_telemetry_map(): void
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

    public function test_environment_api_snapshot_matches_live_payload_on_tenant_subdomain_request(): void
    {
        $this->tenant->makeCurrent();
        $this->prepareSnapshotParityFixture();

        $expected = $this->controllerPayload(
            app(TenantEnvironmentPayloadFactory::class)->buildLiveTenantPayload(
                $this->tenant,
                $this->requestRoot($this->baseUrl),
                $this->requestHost($this->baseUrl),
            )
        );

        app(TenantEnvironmentSnapshotService::class)->repair($this->tenant, 'test_seed_snapshot');

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $this->assertSame($expected, $response->json());
    }

    public function test_environment_api_repairs_missing_snapshot_before_serving_payload(): void
    {
        $this->tenant->makeCurrent();
        $this->prepareSnapshotParityFixture();
        TenantEnvironmentSnapshot::query()->delete();

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('tenant_id', (string) $this->tenant->getKey());
        $response->assertJsonPath('name', $this->tenant->name);

        $this->tenant->makeCurrent();
        $snapshot = TenantEnvironmentSnapshot::current();

        $this->assertNotNull($snapshot);
        $this->assertSame(TenantEnvironmentSnapshotService::SCHEMA_VERSION, (int) $snapshot->schema_version);
        $this->assertNotSame('', (string) $snapshot->snapshot_version);
        $this->assertNotSame('', (string) $snapshot->source_version);
        $this->assertNotNull($snapshot->built_at);
    }

    public function test_environment_api_repairs_schema_drift_snapshot_before_serving_payload(): void
    {
        $this->tenant->makeCurrent();
        $this->prepareSnapshotParityFixture();

        TenantEnvironmentSnapshot::create([
            '_id' => TenantEnvironmentSnapshot::ROOT_ID,
            'schema_version' => 0,
            'source_version' => 'stale-source',
            'snapshot_version' => 'stale-snapshot',
            'snapshot' => [
                'tenant_id' => (string) $this->tenant->getKey(),
                'name' => 'Stale Environment Snapshot',
                'type' => 'tenant',
            ],
        ]);

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('name', $this->tenant->name);

        $this->tenant->makeCurrent();
        $snapshot = TenantEnvironmentSnapshot::current();

        $this->assertSame(TenantEnvironmentSnapshotService::SCHEMA_VERSION, (int) $snapshot?->schema_version);
        $this->assertNotSame('stale-source', (string) $snapshot?->source_version);
        $this->assertNotSame('stale-snapshot', (string) $snapshot?->snapshot_version);
    }

    public function test_environment_api_repairs_invalid_snapshot_payload_before_serving_payload(): void
    {
        $this->tenant->makeCurrent();
        $this->prepareSnapshotParityFixture();

        $seed = app(TenantEnvironmentSnapshotService::class)->repair($this->tenant, 'test_seed_snapshot');
        $seed->forceFill([
            'snapshot_version' => 'partial-current-payload',
            'snapshot' => [
                'tenant_id' => (string) $this->tenant->getKey(),
                'type' => 'tenant',
            ],
            'last_rebuild_reason' => 'test_corrupt_payload',
        ])->save();

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('tenant_id', (string) $this->tenant->getKey());
        $response->assertJsonPath('name', $this->tenant->name);

        $this->tenant->makeCurrent();
        $snapshot = TenantEnvironmentSnapshot::current();

        $this->assertSame('invalid_snapshot', (string) $snapshot?->last_rebuild_reason);
        $this->assertNotSame('partial-current-payload', (string) $snapshot?->snapshot_version);
        $this->assertSame($this->tenant->name, (string) ($snapshot?->snapshot['name'] ?? ''));
        $this->assertSame($this->tenant->subdomain, (string) ($snapshot?->snapshot['subdomain'] ?? ''));
        $this->assertNull($snapshot?->last_rebuild_failed_at);
    }

    public function test_environment_api_does_not_read_another_tenant_snapshot(): void
    {
        $this->tenant->makeCurrent();
        $this->prepareSnapshotParityFixture();
        $primarySnapshot = app(TenantEnvironmentSnapshotService::class)->repair($this->tenant, 'test_primary_seed');
        $primarySourceVersion = (string) $primarySnapshot->source_version;
        TenantEnvironmentSnapshot::query()->delete();

        $secondary = Tenant::create([
            'name' => 'Secondary Snapshot Tenant',
            'subdomain' => 'secondary-snapshot-tenant',
            'app_domains' => ['com.secondary.snapshot'],
        ]);

        $secondary->makeCurrent();
        TenantEnvironmentSnapshot::query()->delete();
        TenantEnvironmentSnapshot::create([
            '_id' => TenantEnvironmentSnapshot::ROOT_ID,
            'schema_version' => TenantEnvironmentSnapshotService::SCHEMA_VERSION,
            'source_version' => $primarySourceVersion,
            'snapshot_version' => 'secondary-poison-snapshot',
            'snapshot' => [
                'tenant_id' => (string) $secondary->getKey(),
                'name' => $secondary->name,
                'type' => 'tenant',
                'subdomain' => $secondary->subdomain,
                'canonical_main_domain' => 'https://secondary-snapshot-tenant.test',
                'domains' => ['secondary-snapshot-tenant.test'],
                'web_domains' => ['secondary-snapshot-tenant.test'],
                'has_explicit_domains' => true,
                'app_domains' => ['com.secondary.snapshot'],
                'branding' => [],
                'telemetry' => [],
                'firebase' => [],
                'push' => [],
            ],
            'last_rebuild_reason' => 'test_secondary_poison_seed',
        ]);

        $this->tenant->makeCurrent();

        $response = $this->get($this->baseUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('tenant_id', (string) $this->tenant->getKey());
        $response->assertJsonMissing(['tenant_id' => (string) $secondary->getKey()]);

        $this->tenant->makeCurrent();
        $primarySnapshot = TenantEnvironmentSnapshot::current();

        $this->assertSame(
            (string) $this->tenant->getKey(),
            (string) ($primarySnapshot?->snapshot['tenant_id'] ?? '')
        );

        $secondary->makeCurrent();
        $secondarySnapshot = TenantEnvironmentSnapshot::current();

        $this->assertSame(
            (string) $secondary->getKey(),
            (string) ($secondarySnapshot?->snapshot['tenant_id'] ?? '')
        );
        $this->assertSame($primarySourceVersion, (string) $secondarySnapshot?->source_version);
    }

    private function prepareSnapshotParityFixture(): void
    {
        TenantPushSettings::query()->delete();
        TenantPushSettings::create([
            'max_ttl_days' => 30,
            'telemetry' => [
                'mixpanel_token' => 'snapshot-token',
                'enabled_events' => ['environment_loaded'],
            ],
            'firebase' => [
                'apiKey' => 'firebase-api-key',
            ],
            'push' => [
                'enabled' => true,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function controllerPayload(array $resolved): array
    {
        $domains = $resolved['domains'] ?? [];
        if (is_array($domains)) {
            $domains = array_map(static function ($domain): string {
                if (is_string($domain)) {
                    return $domain;
                }

                return (string) ($domain['path'] ?? $domain->path ?? '');
            }, $domains);
            $domains = array_values(array_filter($domains, static fn (string $domain): bool => $domain !== ''));
        }

        $payload = [
            'type' => $resolved['type'] ?? null,
            'tenant_id' => $resolved['tenant_id'] ?? null,
            'name' => $resolved['name'] ?? null,
            'subdomain' => $resolved['subdomain'] ?? null,
            'main_domain' => $resolved['main_domain'] ?? null,
            'landlord_domain' => $resolved['landlord_domain'] ?? null,
            'domains' => $domains,
            'app_domains' => $resolved['app_domains'] ?? [],
            'theme_data_settings' => $resolved['theme_data_settings'] ?? [],
            'public_web_metadata' => $resolved['public_web_metadata'] ?? [],
            'telemetry' => $resolved['telemetry'] ?? [],
            'firebase' => $resolved['firebase'] ?? [],
            'push' => $resolved['push'] ?? [],
        ];

        if (array_key_exists('settings', $resolved)) {
            $payload['settings'] = $resolved['settings'];
        }

        return $payload;
    }

    private function requestRoot(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';
        $host = $this->requestHost($url);

        return "{$scheme}://{$host}";
    }

    private function requestHost(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }
}
