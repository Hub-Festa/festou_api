<?php

declare(strict_types=1);

namespace Tests\Feature\PublicWeb;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Shared\Settings\Models\Tenants\TenantSettings;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class OpenAppRedirectTest extends TestCase
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
            'path' => 'tenant-open-app.test',
        ]);

        $_SERVER['HTTP_HOST'] = 'tenant-open-app.test';
        $_SERVER['SERVER_NAME'] = 'tenant-open-app.test';
        $this->withServerVariables([
            'HTTP_HOST' => 'tenant-open-app.test',
        ]);
    }

    public function test_open_app_redirect_for_android_invite_context_uses_app_intent_with_store_fallback(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.boilerplate.openapp',
        ]);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.boilerplate.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get('http://tenant-open-app.test/open-app?path=/invite&code=CODE123&store_channel=web_cta');

        $response->assertRedirect();

        $intent = $this->parseAndroidIntentLocation(
            (string) $response->headers->get('Location')
        );
        $this->assertSame('com.boilerplate.openapp', $intent['package']);

        $intentData = parse_url($intent['data']);
        parse_str((string) ($intentData['query'] ?? ''), $intentQuery);
        $referrer = [];
        parse_str($this->playStoreReferrerFromUrl($intent['fallback_url']), $referrer);

        $tenantOrigin = 'http://tenant-open-app.test';
        $this->assertSame('tenant-open-app.test', $intentData['host'] ?? null);
        $this->assertSame('/invite', $intentData['path'] ?? null);
        $this->assertSame('CODE123', $intentQuery['code'] ?? null);
        $this->assertSame('web_cta', $referrer['store_channel'] ?? null);
        $this->assertSame('CODE123', $referrer['code'] ?? null);
        $this->assertSame('/invite?code=CODE123', $referrer['target_path'] ?? null);
        $this->assertSame("{$tenantOrigin}/invite?code=CODE123", $referrer['link'] ?? null);
    }

    public function test_android_public_routes_redirect_through_open_app_intent_with_original_route_browser_fallback(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.boilerplate.direct',
        ]);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.boilerplate.direct',
                ],
            ],
        ]);

        $tenantOrigin = 'http://tenant-open-app.test';
        $cases = [
            ['url' => $tenantOrigin.'/', 'expected_target_path' => '/'],
            [
                'url' => $tenantOrigin.'/invite?code=CODE123',
                'expected_target_path' => '/invite?code=CODE123',
            ],
            [
                'url' => $tenantOrigin.'/parceiro/profile-slug',
                'expected_target_path' => '/parceiro/profile-slug',
            ],
            [
                'url' => $tenantOrigin.'/agenda/evento/forro?occurrence=occ-1',
                'expected_target_path' => '/agenda/evento/forro?occurrence=occ-1',
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
                ->get($case['url']);

            $response->assertRedirect();
            $openAppLocation = (string) $response->headers->get('Location');
            $this->assertStringStartsWith($tenantOrigin.'/open-app?', $openAppLocation);
            $openAppQuery = [];
            parse_str((string) parse_url($openAppLocation, PHP_URL_QUERY), $openAppQuery);
            $this->assertSame('web_direct', $openAppQuery['store_channel'] ?? null);
            $this->assertSame('android', $openAppQuery['platform_target'] ?? null);
            $this->assertSame('target', $openAppQuery['fallback'] ?? null);

            $intentResponse = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
                ->get($openAppLocation);
            $intentResponse->assertRedirect();
            $this->assertTrue(
                collect($intentResponse->headers->getCookies())->contains(
                    fn ($cookie) => $cookie->getName() === 'shared_web_direct_fallback_target'
                )
            );

            $intent = $this->parseAndroidIntentLocation(
                (string) $intentResponse->headers->get('Location')
            );
            $this->assertSame('com.boilerplate.direct', $intent['package']);

            $intentData = parse_url($intent['data']);
            $intentTarget = ($intentData['path'] ?? '/')
                .(isset($intentData['query']) ? '?'.$intentData['query'] : '');
            $this->assertSame($case['expected_target_path'], $intentTarget);

            $fallback = parse_url($intent['fallback_url']);
            $this->assertSame('tenant-open-app.test', $fallback['host'] ?? null);
            $fallbackTarget = ($fallback['path'] ?? '/')
                .(isset($fallback['query']) ? '?'.$fallback['query'] : '');
            $this->assertSame($case['expected_target_path'], $fallbackTarget);
        }
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Open App', 'subdomain' => 'tenant-open-app', 'app_domains' => ['tenant-open-app.test']],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-open-app.test']
        );

        $service->initialize($payload);
    }

    /**
     * @return array{data: string, package: string, fallback_url: string}
     */
    private function parseAndroidIntentLocation(string $location): array
    {
        $this->assertStringStartsWith('intent://', $location);

        [$dataPart, $intentPart] = explode('#Intent;', $location, 2);
        $this->assertNotSame('', $intentPart);

        $data = preg_replace('/^intent:\/\//', 'https://', $dataPart);
        $segments = explode(';', $intentPart);

        $package = '';
        $fallbackUrl = '';
        foreach ($segments as $segment) {
            if (str_starts_with($segment, 'package=')) {
                $package = substr($segment, strlen('package='));
            }

            if (str_starts_with($segment, 'S.browser_fallback_url=')) {
                $fallbackUrl = rawurldecode(substr(
                    $segment,
                    strlen('S.browser_fallback_url=')
                ));
            }
        }

        return [
            'data' => (string) $data,
            'package' => $package,
            'fallback_url' => $fallbackUrl,
        ];
    }

    private function playStoreReferrerFromUrl(string $url): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        return (string) ($query['referrer'] ?? '');
    }
}
