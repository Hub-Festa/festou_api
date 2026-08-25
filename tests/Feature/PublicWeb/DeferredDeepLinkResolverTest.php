<?php

declare(strict_types=1);

namespace Tests\Feature\PublicWeb;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class DeferredDeepLinkResolverTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $tenant->domains()->withTrashed()->get()->each->forceDelete();
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-deferred.test',
        ]);

        $_SERVER['HTTP_HOST'] = 'tenant-deferred.test';
        $_SERVER['SERVER_NAME'] = 'tenant-deferred.test';
        $this->withServerVariables([
            'HTTP_HOST' => 'tenant-deferred.test',
        ]);
    }

    public function test_deferred_payload_takes_precedence_over_install_referrer_when_present(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'code=CODE123&store_channel=paid_social',
            'install_referrer' => 'target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1&utm_source=organic',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', 'CODE123');
        $response->assertJsonPath('data.target_path', '/invite?code=CODE123');
        $response->assertJsonPath('data.store_channel', 'paid_social');
    }

    public function test_install_referrer_captures_target_path_when_deferred_payload_is_absent(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'install_referrer' => 'target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1&utm_source=organic',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/agenda/evento/forro?occurrence=occ-1');
        $response->assertJsonPath('data.store_channel', 'organic');
    }

    public function test_present_invalid_deferred_payload_does_not_fall_back_to_install_referrer(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'target_path=%2F%2Fevil.example%2Fdrop',
            'install_referrer' => 'code=CODE123&utm_source=organic',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
    }

    public function test_nested_link_payload_captures_code_once(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'ios',
            'deferred_payload' => 'link=%2Finvite%3Fcode%3DCODE999&utm_source=influencer',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', 'CODE999');
        $response->assertJsonPath('data.target_path', '/invite?code=CODE999');
        $response->assertJsonPath('data.store_channel', 'influencer');
    }

    public function test_whitespace_code_collapses_to_nested_payload_capture(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'ios',
            'deferred_payload' => 'code=%20%20&link=%2Finvite%3Fcode%3DABC123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', 'ABC123');
        $response->assertJsonPath('data.target_path', '/invite?code=ABC123');
        $response->assertJsonPath('data.failure_reason', null);
    }

    public function test_malformed_direct_code_fails_closed_even_with_valid_target_path(): void
    {
        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'code=%25BAD&target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
        $response->assertJsonPath('data.failure_reason', 'source_invalid');
    }

    public function test_oversized_direct_target_path_does_not_fall_through_to_nested_payload(): void
    {
        $oversizedTarget = rawurlencode('/'.str_repeat('a', 2048));
        $nestedTarget = rawurlencode('target_path=%2Fparceiro%2Facme');

        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'target_path='.$oversizedTarget.'&link=target_path%3D'.$nestedTarget,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
        $response->assertJsonPath('data.failure_reason', 'source_invalid');
    }

    public function test_collapse_to_absence_target_path_falls_through_to_nested_payload_capture(): void
    {
        $nestedTarget = rawurlencode('target_path=%2Fparceiro%2Facme');

        $response = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'target_path=%2F%2Fevil.example%2Fdrop&link='.$nestedTarget,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/parceiro/acme');
        $response->assertJsonPath('data.failure_reason', null);
    }

    public function test_store_channel_fallback_is_metadata_only_and_normalized(): void
    {
        $normalized = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'code=CODE123',
            'store_channel' => 'App_Store',
        ]);

        $normalized->assertOk();
        $normalized->assertJsonPath('data.status', 'captured');
        $normalized->assertJsonPath('data.code', 'CODE123');
        $normalized->assertJsonPath('data.store_channel', 'app_store');

        $invalid = $this->postJson('http://tenant-deferred.test/api/v1/deep-links/deferred/resolve', [
            'platform' => 'android',
            'deferred_payload' => 'code=CODE123',
            'store_channel' => 'Bad Channel!',
        ]);

        $invalid->assertOk();
        $invalid->assertJsonPath('data.status', 'captured');
        $invalid->assertJsonPath('data.code', 'CODE123');
        $invalid->assertJsonPath('data.store_channel', null);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Deferred', 'subdomain' => 'tenant-deferred', 'app_domains' => ['tenant-deferred.test']],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-deferred.test']
        );

        $service->initialize($payload);
    }
}
