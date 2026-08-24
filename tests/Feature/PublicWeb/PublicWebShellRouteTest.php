<?php

declare(strict_types=1);

namespace Tests\Feature\PublicWeb;

use App\Application\Initialization\InitializationPayload;
use App\Application\PublicWeb\ProjectPublicShellRouteRegistry;
use App\Application\PublicWeb\PublicWebMetadataService;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventOccurrence;
use Illuminate\Http\Request;
use Shared\Settings\Models\Tenants\TenantSettings;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PublicWebShellRouteTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private string $previousShellPath = '';

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

        $this->previousShellPath = (string) getenv('FLUTTER_WEB_SHELL_PATH');
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

        $this->setShellPathOverride($this->shellPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->shellPath ?? '')) {
            @unlink($this->shellPath);
        }

        $this->restorePreviousShellPath();

        parent::tearDown();
    }

    public function testRootReturnsHtmlWithInjectedMetadataBeforeBootstrap(): void
    {
        $response = $this->get('http://tenant-shell.test/?utm_source=landing');

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

    public function testRootCanRenderUsingTheConfiguredGenericRuntimeShellPath(): void
    {
        $runtimeShellPath = '/opt/web-shell/index.html';

        $this->assertFileExists(
            $runtimeShellPath,
            'The Docker app must expose the canonical generic runtime shell mount.'
        );

        $this->setShellPathOverride($runtimeShellPath);

        $response = $this->get('http://tenant-shell.test/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertStringContainsString('flutter_bootstrap.js', $html);
    }

    public function testBootstrapTenantRoutesAcceptOneLabelAndExternalHostsButRejectNestedLandlordHosts(): void
    {
        $oneLabel = $this->getJson('http://tenant-shell.nginx/api/v1/environment');

        $oneLabel->assertOk()
            ->assertJsonPath('subdomain', 'tenant-shell');

        $external = $this->getJson('http://tenant-shell.external.test/api/v1/environment');

        $external->assertOk()
            ->assertJsonPath('subdomain', 'tenant-shell');

        $nested = $this->getJson('http://platform-test.extra.nginx/api/v1/environment');

        $nested->assertNotFound();
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

        $response = $this->get('http://tenant-shell.test/parceiro/creator-collective?utm_source=profile');

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

    public function testPartnerRouteFallsBackWhenProfileIsInactive(): void
    {
        config([
            'favorites.publicly_navigable_profile_types' => ['artist'],
        ]);

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::create([
            'account_id' => 'account-inactive-1',
            'profile_type' => 'artist',
            'display_name' => 'Inativo',
            'slug' => 'inativo',
            'avatar_url' => 'https://tenant-shell.test/media/inativo-avatar.png',
            'cover_url' => 'https://tenant-shell.test/media/inativo-cover.png',
            'is_active' => false,
        ])->setAttribute('description', '<p>Perfil inativo.</p>')->save();

        $response = $this->get('http://tenant-shell.test/parceiro/inativo?utm_source=inactive');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/parceiro/inativo">',
            $html
        );
        $this->assertStringNotContainsString('Inativo | Tenant Shell', $html);
        $this->assertStringNotContainsString('inativo-cover.png', $html);
        $this->assertStringNotContainsString('Perfil inativo.', $html);
    }

    public function testPartnerRouteFallsBackWhenProfileDoesNotExist(): void
    {
        config([
            'favorites.publicly_navigable_profile_types' => ['artist'],
        ]);

        $response = $this->get('http://tenant-shell.test/parceiro/perfil-inexistente?utm_source=missing');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/parceiro/perfil-inexistente">',
            $html
        );
        $this->assertStringNotContainsString('Perfil Inexistente | Tenant Shell', $html);
    }

    public function testInviteRouteUsesConfiguredProjectExtensionMetadata(): void
    {
        $response = $this->get('http://tenant-shell.test/invite?code=CODE123');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Invite Landing | Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<meta name="description" content="Open invite code CODE123 from the configured project route.">',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/invite">',
            $html
        );
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

        $response = $this->get('http://tenant-shell.test/agenda/evento/sunset-session?utm_source=event');

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

    public function testStaticAssetRouteUsesConfiguredProjectExtensionMetadata(): void
    {
        $response = $this->get('http://tenant-shell.test/static/festival-poster');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<title>Festival Poster | Tenant Shell</title>', $html);
        $this->assertStringContainsString(
            '<meta name="description" content="Configured static-asset metadata for Festival Poster.">',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://tenant-shell.test/static/festival-poster">',
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

    public function test_known_android_in_app_browser_skips_direct_open_app_redirect(): void
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

        $response = $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) Instagram 341.0.0.0.1'
        )->get('http://tenant-shell.test/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertDontSee('/open-app?', false);
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

    public function testConfiguredOneSegmentRoutesFailClosedForDeeperPaths(): void
    {
        foreach ([
            '/parceiro/creator-collective/extra',
            '/agenda/evento/sunset-session/extra',
            '/static/festival-poster/extra',
        ] as $path) {
            $this->get('http://tenant-shell.test'.$path)->assertNotFound();
        }
    }

    public function testNeutralProjectOwnedMetadataRoutesUseTheirConfiguredCanonicalPaths(): void
    {
        Tenant::query()->firstOrFail()->makeCurrent();

        config([
            'favorites.publicly_navigable_profile_types' => ['artist'],
        ]);

        AccountProfile::create([
            'account_id' => 'neutral-profile-account',
            'profile_type' => 'artist',
            'display_name' => 'Neutral Profile',
            'slug' => 'neutral-profile',
            'avatar_url' => 'https://tenant-shell.test/media/neutral-profile.png',
            'cover_url' => 'https://tenant-shell.test/media/neutral-profile-cover.png',
            'is_active' => true,
        ])->setAttribute('description', '<p>Neutral profile description.</p>')->save();

        EventOccurrence::create([
            'slug' => 'neutral-event',
            'title' => 'Neutral Event',
            'is_event_published' => true,
            'cover_url' => 'https://tenant-shell.test/media/neutral-event-cover.png',
        ])->setAttribute('description', '<p>Neutral event description.</p>')->save();

        $previousConfigPath = (string) getenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
        $previousRequest = $this->app->bound('request') ? $this->app->make('request') : null;

        try {
            $neutralFixture = base_path('tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php');
            putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$neutralFixture);
            $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $neutralFixture;
            $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $neutralFixture;

            $registry = new ProjectPublicShellRouteRegistry(
                $this->app,
                $this->app->make(PublicWebMetadataService::class),
            );

            $profileRequest = Request::create('http://tenant-shell.test/project-profiles/neutral-profile?utm_source=neutral');
            $this->app->instance('request', $profileRequest);
            $profileMetadata = $registry->metadataForRoute(
                $profileRequest,
                'project_profile',
                'neutral-profile',
            );
            $this->assertSame(
                'http://tenant-shell.test/project-profiles/neutral-profile',
                $profileMetadata['canonical_url']
            );
            $this->assertSame('Neutral Profile | Tenant Shell', $profileMetadata['title']);

            $eventRequest = Request::create('http://tenant-shell.test/project-events/neutral-event?utm_source=neutral');
            $this->app->instance('request', $eventRequest);
            $eventMetadata = $registry->metadataForRoute(
                $eventRequest,
                'project_event',
                'neutral-event',
            );
            $this->assertSame(
                'http://tenant-shell.test/project-events/neutral-event',
                $eventMetadata['canonical_url']
            );
            $this->assertSame('Neutral Event | Tenant Shell', $eventMetadata['title']);
        } finally {
            if ($previousConfigPath === '') {
                putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
                unset($_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'], $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE']);
            } else {
                putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$previousConfigPath);
                $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $previousConfigPath;
                $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $previousConfigPath;
            }

            if ($previousRequest !== null) {
                $this->app->instance('request', $previousRequest);
            }
        }
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

    private function setShellPathOverride(string $path): void
    {
        putenv('FLUTTER_WEB_SHELL_PATH='.$path);
        $_ENV['FLUTTER_WEB_SHELL_PATH'] = $path;
        $_SERVER['FLUTTER_WEB_SHELL_PATH'] = $path;
    }

    private function restorePreviousShellPath(): void
    {
        if ($this->previousShellPath === '') {
            putenv('FLUTTER_WEB_SHELL_PATH');
            unset($_ENV['FLUTTER_WEB_SHELL_PATH'], $_SERVER['FLUTTER_WEB_SHELL_PATH']);

            return;
        }

        $this->setShellPathOverride($this->previousShellPath);
    }
}
