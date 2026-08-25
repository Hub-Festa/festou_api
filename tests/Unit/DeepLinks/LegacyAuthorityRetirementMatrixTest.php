<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use App\Integration\DeepLinks\AppLinksPatchGuard;
use Illuminate\Validation\ValidationException;
use Shared\DeepLinks\Application\DeepLinkAssociationService;
use Shared\DeepLinks\Application\ProjectRoutePolicyCompiler;
use Shared\DeepLinks\Application\WebToAppPromotionService;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;
use Shared\Settings\Support\SettingsNamespaceDefinition;
use Tests\TestCase;

class LegacyAuthorityRetirementMatrixTest extends TestCase
{
    public function testRuntimeSourcesDoNotRetainBellugaRouteLiteralsOrRetiredIosPaths(): void
    {
        $sources = [
            base_path('packages/shared/deep_links/src/Application/WebToAppPromotionService.php'),
            base_path('packages/shared/deep_links/src/Application/DeepLinkAssociationService.php'),
            base_path('packages/shared/deep_links/src/Application/DeferredDeepLinkResolverService.php'),
            base_path('packages/shared/deep_links/src/Http/Web/Controllers/OpenAppRedirectController.php'),
            app_path('Http/Controllers/TenantPublicShellController.php'),
            app_path('Integration/DeepLinks/AppLinksSettingsSourceAdapter.php'),
        ];

        $prohibitedLiterals = [
            '/invite',
            '/convites',
            '/baixe-o-app',
            '/descobrir',
            '/mapa',
            '/mapa/poi',
            '/location/permission',
            '/parceiro/',
            '/agenda/evento/',
            '/static/',
        ];

        foreach ($sources as $path) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents, sprintf('Expected runtime source `%s` to be readable.', $path));

            foreach ($prohibitedLiterals as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    $contents,
                    sprintf('Runtime source `%s` must not retain legacy route literal `%s`.', $path, $literal),
                );
            }

            $this->assertStringNotContainsString(
                'ios.paths',
                $contents,
                sprintf('Runtime source `%s` must not consume retired app_links.ios.paths authority.', $path),
            );
        }
    }

    public function testAssociationOutputIgnoresLegacyTenantPathBearingSettings(): void
    {
        $association = $this->makeAssociationService([
            'ios' => [
                'team_id' => 'TENANT1234',
                'bundle_id' => 'com.example.tenant',
                'paths' => ['/legacy-tenant*'],
            ],
        ])->buildAppleAppSiteAssociation();

        $this->assertSame(
            ['apps' => [], 'details' => [[
                'appID' => 'TENANT1234.com.example.tenant',
                'paths' => ['/invite*', '/convites*'],
            ]]],
            $association['applinks'] ?? null,
        );
    }

    public function testAssociationOutputIgnoresLegacyLandlordPathBearingSettings(): void
    {
        $association = $this->makeAssociationService([
            'ios' => [
                'team_id' => 'LANDLORD1',
                'bundle_id' => 'com.example.landlord',
                'paths' => ['/legacy-landlord*'],
            ],
        ])->buildAppleAppSiteAssociation();

        $this->assertSame(
            ['apps' => [], 'details' => [[
                'appID' => 'LANDLORD1.com.example.landlord',
                'paths' => ['/invite*', '/convites*'],
            ]]],
            $association['applinks'] ?? null,
        );
    }

    public function testRetiredIosPathAuthorityIsRejectedForTenantAndLandlordWrites(): void
    {
        foreach (['tenant', 'landlord'] as $scope) {
            try {
                $this->makeGuard()->guard($scope, null, 'app_links', [
                    'ios' => [
                        'paths' => ['/legacy*'],
                    ],
                ], $this->makeDefinition($scope));

                $this->fail(sprintf('Expected retired ios.paths authority to be rejected for `%s` scope.', $scope));
            } catch (ValidationException $exception) {
                $this->assertSame([
                    'Project deep-link route policy owns Apple association paths; app_links.ios.paths is retired.',
                ], $exception->errors()['ios.paths'] ?? []);
            }
        }
    }

    public function testNeutralAndGenericPoliciesDoNotRecoverLegacyBellugaAuthorities(): void
    {
        $neutralService = $this->makePromotionService(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_neutral.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );
        $genericRequiredService = $this->makePromotionService(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_generic_required.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php',
        );

        $this->assertSame('/', $neutralService->normalizeTargetPath('/invite?code=CODE123'));
        $this->assertSame('/', $neutralService->normalizeTargetPath('/baixe-o-app?redirect=%2Finvite'));
        $this->assertSame('/privacy-policy', $genericRequiredService->normalizeTargetPath('/privacy-policy'));
        $this->assertSame('/', $genericRequiredService->normalizeTargetPath('/invite?code=CODE123'));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function makeAssociationService(array $settings): DeepLinkAssociationService
    {
        return new DeepLinkAssociationService(
            new class implements AppLinksIdentifierGatewayContract {
                public function identifierForPlatform(string $platform): ?string
                {
                    return null;
                }

                public function hasIdentifierForPlatform(string $platform): bool
                {
                    return false;
                }
            },
            new class($settings) implements AppLinksSettingsSourceContract {
                /**
                 * @param  array<string, mixed>  $settings
                 */
                public function __construct(
                    private readonly array $settings,
                ) {}

                public function currentAppLinksSettings(): array
                {
                    return $this->settings;
                }
            },
            $this->compilePolicy(
                'tests/Fixtures/DeepLinks/project_deep_link_route_policy_belluga.php',
                'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
            ),
        );
    }

    private function makePromotionService(string $policyFixture, string $inventoryFixture): WebToAppPromotionService
    {
        return new WebToAppPromotionService(
            new class implements AppLinksIdentifierGatewayContract {
                public function identifierForPlatform(string $platform): ?string
                {
                    return null;
                }

                public function hasIdentifierForPlatform(string $platform): bool
                {
                    return false;
                }
            },
            $this->compilePolicy($policyFixture, $inventoryFixture),
        );
    }

    private function makeGuard(): AppLinksPatchGuard
    {
        return new AppLinksPatchGuard(
            new class implements AppLinksIdentifierGatewayContract {
                public function identifierForPlatform(string $platform): ?string
                {
                    return null;
                }

                public function hasIdentifierForPlatform(string $platform): bool
                {
                    return false;
                }
            },
            new class implements AppLinksSettingsSourceContract {
                public function currentAppLinksSettings(): array
                {
                    return [];
                }
            },
        );
    }

    private function makeDefinition(string $scope): SettingsNamespaceDefinition
    {
        return new SettingsNamespaceDefinition(
            namespace: 'app_links',
            scope: $scope,
            label: 'App Links',
            groupLabel: 'Mobile',
            ability: null,
            fields: [
                'android.sha256_cert_fingerprints' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Android SHA-256 Fingerprints',
                    'default' => [],
                ],
                'android.enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Android Published',
                    'default' => false,
                ],
                'android.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Android Store URL',
                    'default' => null,
                ],
                'ios.team_id' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Apple Team ID',
                    'default' => null,
                ],
                'ios.enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'iOS Published',
                    'default' => false,
                ],
                'ios.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'iOS Store URL',
                    'default' => null,
                ],
            ],
        );
    }

    private function compilePolicy(string $policyFixture, string $inventoryFixture): \Shared\DeepLinks\Application\CompiledProjectRoutePolicy
    {
        return (new ProjectRoutePolicyCompiler(
            new class($policyFixture) implements ProjectRoutePolicySourceContract {
                public function __construct(
                    private readonly string $fixture,
                ) {}

                public function currentProjectRoutePolicy(): ?array
                {
                    return require base_path($this->fixture);
                }
            },
            new class($inventoryFixture) implements PublicShellRouteInventorySourceContract {
                public function __construct(
                    private readonly string $fixture,
                ) {}

                public function currentPublicShellRouteInventory(): array
                {
                    $config = require base_path($this->fixture);

                    return array_merge([
                        [
                            'route_id' => 'root_landing',
                            'shape' => 'exact',
                            'path' => '/',
                            'kind' => 'generic_builtin',
                        ],
                        [
                            'route_id' => 'privacy_policy',
                            'shape' => 'exact',
                            'path' => '/privacy-policy',
                            'kind' => 'generic_builtin',
                        ],
                    ], $config['routes']);
                }
            },
        ))->compile();
    }
}
