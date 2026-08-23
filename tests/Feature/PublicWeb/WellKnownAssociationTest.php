<?php

declare(strict_types=1);

namespace Tests\Feature\PublicWeb;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Shared\Settings\Models\Landlord\LandlordSettings;
use Shared\Settings\Models\Tenants\TenantSettings;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class WellKnownAssociationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->domains()->withTrashed()->get()->each->forceDelete();
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-association.test',
        ]);

        Tenant::forgetCurrent();
        LandlordSettings::query()->delete();
        TenantSettings::query()->delete();
    }

    public function test_assetlinks_uses_tenant_settings_payload(): void
    {
        $this->tenant->makeCurrent();
        $this->upsertTypedAppDomain(Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.example.tenant.android');

        TenantSettings::query()->updateOrCreate(['_id' => TenantSettings::ROOT_ID], [
            'app_links' => [
                'android' => [
                    'sha256_cert_fingerprints' => [
                        '3e:72:4c:54:e9:53:26:7d:e6:e1:9b:f8:dc:53:30:2a:08:01:8e:36:40:4d:0c:ca:98:3b:46:84:53:e7:a9:a9',
                    ],
                ],
            ],
        ]);
        Tenant::forgetCurrent();

        $response = $this->get('http://tenant-association.test/.well-known/assetlinks.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('0.target.namespace', 'android_app');
        $response->assertJsonPath('0.target.package_name', 'com.example.tenant.android');
        $response->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9'
        );
    }

    public function test_apple_app_site_association_uses_tenant_settings_payload(): void
    {
        $this->tenant->makeCurrent();
        $this->upsertTypedAppDomain(Tenant::DOMAIN_TYPE_APP_IOS, 'com.example.tenant.ios');

        TenantSettings::query()->updateOrCreate(['_id' => TenantSettings::ROOT_ID], [
            'app_links' => [
                'ios' => [
                    'team_id' => 'ABCDE12345',
                    'paths' => ['/invite*', '/convites*'],
                ],
            ],
        ]);
        Tenant::forgetCurrent();

        $response = $this->get('http://tenant-association.test/.well-known/apple-app-site-association');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('applinks.apps', []);
        $response->assertJsonPath('applinks.details.0.appID', 'ABCDE12345.com.example.tenant.ios');
        $response->assertJsonPath('applinks.details.0.paths.0', '/invite*');
    }

    public function test_well_known_endpoints_return_json_fallback_when_tenant_credentials_are_missing(): void
    {
        $this->tenant->makeCurrent();
        $this->tenant->domains()
            ->whereIn('type', [Tenant::DOMAIN_TYPE_APP_ANDROID, Tenant::DOMAIN_TYPE_APP_IOS])
            ->delete();

        TenantSettings::query()->updateOrCreate(['_id' => TenantSettings::ROOT_ID], [
            'app_links' => [],
        ]);
        Tenant::forgetCurrent();

        $assetLinks = $this->get('http://tenant-association.test/.well-known/assetlinks.json');
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertExactJson([]);

        $apple = $this->get('http://tenant-association.test/.well-known/apple-app-site-association');
        $apple->assertOk();
        $apple->assertHeader('Content-Type', 'application/json');
        $apple->assertDontSee('<!DOCTYPE html>', false);
        $apple->assertJsonPath('applinks.apps', []);
        $apple->assertJsonPath('applinks.details', []);
    }

    public function test_tenant_settings_take_precedence_over_landlord_fallback(): void
    {
        $this->tenant->makeCurrent();
        $this->upsertTypedAppDomain(Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.tenant.priority');

        LandlordSettings::query()->updateOrCreate(['_id' => LandlordSettings::ROOT_ID], [
            'app_links' => [
                'android' => [
                    'sha256_cert_fingerprints' => [
                        '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF',
                    ],
                ],
            ],
        ]);

        TenantSettings::query()->updateOrCreate(['_id' => TenantSettings::ROOT_ID], [
            'app_links' => [
                'android' => [
                    'sha256_cert_fingerprints' => [
                        'FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00:FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00',
                    ],
                ],
            ],
        ]);
        Tenant::forgetCurrent();

        $assetLinks = $this->get('http://tenant-association.test/.well-known/assetlinks.json');
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertJsonPath('0.target.package_name', 'com.tenant.priority');
        $assetLinks->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            'FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00:FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00'
        );
    }

    public function test_landlord_well_known_endpoints_use_landlord_settings_payloads(): void
    {
        Tenant::forgetCurrent();

        LandlordSettings::query()->updateOrCreate(['_id' => LandlordSettings::ROOT_ID], [
            'app_links' => [
                'android' => [
                    'package_name' => 'com.example.landlord.admin',
                    'sha256_cert_fingerprints' => [
                        '0f:1e:2d:3c:4b:5a:69:78:87:96:a5:b4:c3:d2:e1:f0:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff:00',
                    ],
                ],
                'ios' => [
                    'team_id' => 'LANDLORD1',
                    'bundle_id' => 'com.example.landlord.admin',
                    'paths' => ['/invite*', '/convites*'],
                ],
            ],
        ]);

        $assetLinks = $this->get("http://{$this->host}/.well-known/assetlinks.json");
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertJsonPath('0.target.namespace', 'android_app');
        $assetLinks->assertJsonPath('0.target.package_name', 'com.example.landlord.admin');
        $assetLinks->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            '0F:1E:2D:3C:4B:5A:69:78:87:96:A5:B4:C3:D2:E1:F0:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00'
        );

        $apple = $this->get("http://{$this->host}/.well-known/apple-app-site-association");
        $apple->assertOk();
        $apple->assertHeader('Content-Type', 'application/json');
        $apple->assertDontSee('<!DOCTYPE html>', false);
        $apple->assertJsonPath('applinks.apps', []);
        $apple->assertJsonPath('applinks.details.0.appID', 'LANDLORD1.com.example.landlord.admin');
        $apple->assertJsonPath('applinks.details.0.paths.0', '/invite*');
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Association', 'subdomain' => 'tenant-association', 'app_domains' => ['tenant-association.test']],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'fixture-password-placeholder'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-association.test']
        );

        $service->initialize($payload);
    }

    private function upsertTypedAppDomain(string $type, string $identifier): void
    {
        $existing = $this->tenant->domains()
            ->where('type', $type)
            ->first();

        if ($existing === null) {
            $this->tenant->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        $existing->save();
    }
}
