<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Environment;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Environment\TenantEnvironmentPayloadFactory;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantEnvironmentSnapshotServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();
        TenantEnvironmentSnapshot::query()->delete();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        $this->refreshLandlordAndTenantDatabases();

        parent::tearDown();
    }

    public function test_read_resolved_payload_serves_current_snapshot_without_rebuilding(): void
    {
        $service = app(TenantEnvironmentSnapshotService::class);
        $expected = $service->readResolvedPayload($this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');
        $snapshotBefore = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshotBefore);

        $guardedService = new TenantEnvironmentSnapshotService(
            new class(app(BrandingPublicWebMediaService::class)) extends TenantEnvironmentPayloadFactory
            {
                public function buildSnapshotSource(Tenant $tenant): array
                {
                    throw new RuntimeException('snapshot hit should not rebuild');
                }
            }
        );

        $actual = $guardedService->readResolvedPayload($this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');
        $snapshotAfter = TenantEnvironmentSnapshot::current();

        $this->assertSame($expected, $actual);
        $this->assertEquals($snapshotBefore->last_rebuild_started_at, $snapshotAfter?->last_rebuild_started_at);
        $this->assertNull($snapshotAfter?->last_rebuild_failed_at);
        $this->assertSame((string) $snapshotBefore->snapshot_version, (string) $snapshotAfter?->snapshot_version);
    }

    public function test_read_resolved_payload_repairs_stale_source_version(): void
    {
        $service = app(TenantEnvironmentSnapshotService::class);
        $service->repair($this->tenant, 'test_seed');

        $snapshotBefore = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshotBefore);

        $this->tenant->name = 'Tenant Beta Renamed';
        $this->tenant->save();

        $payload = $service->readResolvedPayload($this->tenant->fresh() ?? $this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');

        $snapshotAfter = TenantEnvironmentSnapshot::current();

        $this->assertSame('Tenant Beta Renamed', $payload['name']);
        $this->assertNotSame((string) $snapshotBefore->source_version, (string) $snapshotAfter?->source_version);
        $this->assertNotSame((string) $snapshotBefore->snapshot_version, (string) $snapshotAfter?->snapshot_version);
    }

    public function test_read_resolved_payload_serves_last_valid_snapshot_when_stale_repair_fails(): void
    {
        $service = app(TenantEnvironmentSnapshotService::class);
        $expected = $service->readResolvedPayload($this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');
        $snapshotBefore = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshotBefore);

        $this->tenant->name = 'Tenant Beta Stale Repair Failure';
        $this->tenant->save();

        $guardedService = new TenantEnvironmentSnapshotService(
            new class(app(BrandingPublicWebMediaService::class)) extends TenantEnvironmentPayloadFactory
            {
                public function buildSnapshotSource(Tenant $tenant): array
                {
                    throw new RuntimeException('forced stale repair failure');
                }

                public function buildLiveTenantPayload(Tenant $tenant, ?string $requestRoot, ?string $requestHost): array
                {
                    throw new RuntimeException('live fallback should not run with a usable last-valid snapshot');
                }
            }
        );

        $actual = $guardedService->readResolvedPayload($this->tenant->fresh() ?? $this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');
        $snapshotAfter = TenantEnvironmentSnapshot::current();

        $this->assertSame($expected, $actual);
        $this->assertSame((string) $snapshotBefore->source_version, (string) $snapshotAfter?->source_version);
        $this->assertNotNull($snapshotAfter?->last_rebuild_failed_at);
        $this->assertStringContainsString('forced stale repair failure', (string) $snapshotAfter?->last_rebuild_error);
    }

    public function test_read_resolved_payload_falls_back_to_live_payload_when_repair_fails_without_usable_snapshot(): void
    {
        $guardedService = new TenantEnvironmentSnapshotService(
            new class(app(BrandingPublicWebMediaService::class)) extends TenantEnvironmentPayloadFactory
            {
                public function buildSnapshotSource(Tenant $tenant): array
                {
                    throw new RuntimeException('forced snapshot rebuild failure');
                }

                public function buildLiveTenantPayload(Tenant $tenant, ?string $requestRoot, ?string $requestHost): array
                {
                    return [
                        'tenant_id' => (string) $tenant->getKey(),
                        'name' => 'Live Fallback Payload',
                        'type' => 'tenant',
                    ];
                }
            }
        );

        $payload = $guardedService->readResolvedPayload($this->tenant, 'https://tenant-beta.test', 'tenant-beta.test');

        $this->assertSame('Live Fallback Payload', $payload['name']);

        $snapshot = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshot?->last_rebuild_failed_at);
        $this->assertStringContainsString('forced snapshot rebuild failure', (string) $snapshot?->last_rebuild_error);
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
