<?php

declare(strict_types=1);

namespace Tests\Feature\PublicWeb;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventOccurrence;
use Shared\Settings\Models\Tenants\TenantSettings;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PublicWebShellRouteTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private string $shellPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $_SERVER['HTTP_HOST'] = 'tenant-shell.test';
        $_SERVER['SERVER_NAME'] = 'tenant-shell.test';
        $this->withServerVariables([
            'HTTP_HOST' => 'tenant-shell.test',
        ]);

        $this->shellPath = tempnam(sys_get_temp_dir(), 'public-web-shell-').'.html';
        file_put_contents($this->shellPath, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="description" content="Old shell description">
  <link rel="canonical" href="https://example.invalid/old">
  <title>Old Shell Title</title>
</head>
<body>
  <div id="app"></div>
  <script src="flutter_bootstrap.js"></script>
</body>
</html>
HTML);

        putenv('FLUTTER_WEB_SHELL_PATH='.$this->shellPath);
        $_ENV['FLUTTER_WEB_SHELL_PATH'] = $this->shellPath;
        $_SERVER['FLUTTER_WEB_SHELL_PATH'] = $this->shellPath;
    }

    protected function tearDown(): void
    {
        if (is_file($this->shellPath ?? '')) {
            @unlink($this->shellPath);
        }

        putenv('FLUTTER_WEB_SHELL_PATH');
        unset($_ENV['FLUTTER_WEB_SHELL_PATH'], $_SERVER['FLUTTER_WEB_SHELL_PATH']);

        parent::tearDown();
    }

    public function testRootReturnsHtmlWithInjectedMetadataBeforeBootstrap(): void
    {
        $response = $this->get('http://tenant-shell.test/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="[^"]+">/',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/">',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<meta property="og:image" content="http:\/\/tenant-shell\.test\/[^"]+">/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<meta name="twitter:image" content="http:\/\/tenant-shell\.test\/[^"]+">/',
            $html
        );
        $this->assertStringNotContainsString('Old Shell Title', $html);
        $this->assertStringNotContainsString('Old shell description', $html);

        $titlePosition = strpos($html, '<title>Tenant Shell</title>');
        $bootstrapPosition = strpos($html, 'flutter_bootstrap.js');
        $this->assertNotFalse($titlePosition);
        $this->assertNotFalse($bootstrapPosition);
        $this->assertLessThan($bootstrapPosition, $titlePosition);
    }

    public function testPartnerRouteReturnsRichMetadataFromLocalAccountProfile(): void
    {
        config([
            'favorites.publicly_navigable_profile_types' => ['artist'],
        ]);

        Tenant::query()->firstOrFail()->makeCurrent();

        $account = Account::create([
            'name' => 'Creator Collective',
        ]);

        AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Creator Collective',
            'slug' => 'creator-collective',
            'avatar_url' => 'https://tenant-shell.test/media/creator-avatar.png',
            'cover_url' => 'https://tenant-shell.test/media/creator-cover.png',
            'is_active' => true,
        ])->setAttribute('description', '<p>A featured creator profile with expanded editorial context.</p>')->save();

        $response = $this->get('http://tenant-shell.test/parceiro/creator-collective');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Creator Collective | Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<meta name="description" content="A featured creator profile with expanded editorial context.">',
            $html
        );
        $this->assertStringContainsString(
            '<meta property="og:image" content="https://tenant-shell.test/media/creator-cover.png">',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/parceiro/creator-collective">',
            $html
        );
    }

    public function testPartnerRouteFallsBackWhenProfileTypeIsNotPubliclyNavigable(): void
    {
        config([
            'favorites.publicly_navigable_profile_types' => ['artist'],
        ]);

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::create([
            'account_id' => 'account-reservado-1',
            'profile_type' => 'restaurant',
            'display_name' => 'Reservado',
            'slug' => 'reservado',
            'avatar_url' => 'https://tenant-shell.test/media/reservado-avatar.png',
            'cover_url' => 'https://tenant-shell.test/media/reservado-cover.png',
            'is_active' => true,
        ])->setAttribute('description', '<p>Perfil que não deveria aparecer como rich metadata.</p>')->save();

        $response = $this->get('http://tenant-shell.test/parceiro/reservado');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/parceiro/reservado">',
            $html
        );
        $this->assertStringNotContainsString('Reservado | Tenant Shell', $html);
        $this->assertStringNotContainsString('reservado-cover.png', $html);
        $this->assertStringNotContainsString('Perfil que não deveria aparecer como rich metadata.', $html);
    }

    public function testEventRouteReturnsRichMetadataFromLocalEventOccurrence(): void
    {
        Tenant::query()->firstOrFail()->makeCurrent();

        EventOccurrence::create([
            'slug' => 'sunset-session',
            'title' => 'Sunset Session',
            'is_event_published' => true,
            'cover_url' => 'https://tenant-shell.test/media/sunset-session-cover.png',
        ])->setAttribute('description', '<p>An outdoor showcase with a late-afternoon program.</p>')->save();

        $response = $this->get('http://tenant-shell.test/agenda/evento/sunset-session');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Sunset Session | Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<meta name="description" content="An outdoor showcase with a late-afternoon program.">',
            $html
        );
        $this->assertStringContainsString(
            '<meta property="og:image" content="https://tenant-shell.test/media/sunset-session-cover.png">',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/agenda/evento/sunset-session">',
            $html
        );
    }

    public function test_android_direct_public_fallback_cookie_renders_shell_once_without_retriggering_open_app(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $tenant->domains()->withTrashed()->get()->each->forceDelete();
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_WEB,
            'path' => 'tenant-shell.test',
        ]);
        $tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.shell',
        ]);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.tenant.shell',
                ],
            ],
        ]);

        $openAppResponse = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get('http://tenant-shell.test/open-app?path=%2F&store_channel=web_direct&platform_target=android&fallback=target');
        $openAppResponse->assertRedirect();

        $bypassCookie = collect($openAppResponse->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === 'shared_web_direct_fallback_target'
        );
        $this->assertNotNull($bypassCookie);

        $response = $this->call(
            'GET',
            '/',
            [],
            [
                $bypassCookie->getName() => $bypassCookie->getValue(),
            ],
            [],
            [
                'HTTP_HOST' => 'tenant-shell.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8)',
                'HTTPS' => 'on',
            ],
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertDontSee('/open-app?', false);
        $this->assertTrue(
            collect($response->headers->getCookies())->contains(
                fn ($cookie) => $cookie->getName() === 'shared_web_direct_fallback_target'
                    && $cookie->getExpiresTime() <= time()
            )
        );
    }

    public function testEventRouteFallsBackWhenEventIsNotPublished(): void
    {
        Tenant::query()->firstOrFail()->makeCurrent();

        EventOccurrence::create([
            'slug' => 'festival-privado',
            'title' => 'Festival Privado',
            'is_event_published' => false,
            'cover_url' => 'https://tenant-shell.test/media/festival-privado-cover.png',
        ])->setAttribute('description', '<p>Evento que não deve renderizar metadata rica.</p>')->save();

        $response = $this->get('http://tenant-shell.test/agenda/evento/festival-privado');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/agenda/evento/festival-privado">',
            $html
        );
        $this->assertStringNotContainsString('Festival Privado | Tenant Shell', $html);
        $this->assertStringNotContainsString('festival-privado-cover.png', $html);
        $this->assertStringNotContainsString('Evento que não deve renderizar metadata rica.', $html);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Boilerplate', 'description' => 'Boilerplate description'],
            tenant: ['name' => 'Tenant Shell', 'subdomain' => 'tenant-shell', 'description' => 'Tenant Shell description'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-shell.test']
        );

        $service->initialize($payload);
    }
}
