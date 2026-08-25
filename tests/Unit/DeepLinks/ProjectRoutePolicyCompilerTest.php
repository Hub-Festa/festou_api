<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Shared\DeepLinks\Application\CompanionRouteInventorySnapshot;
use Shared\DeepLinks\Application\ProjectRoutePolicyCompiler;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;
use Tests\TestCase;

class ProjectRoutePolicyCompilerTest extends TestCase
{
    public function testNullSourceCompilesExplicitNeutralOptOutPolicy(): void
    {
        $compiled = $this->makeCompiler(
            policy: null,
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        )->compile();

        $this->assertSame('explicit_neutral_opt_out', $compiled->availabilityMode());
        $this->assertSame([], $compiled->routes());
        $this->assertSame([], $compiled->associationPaths());
        $this->assertNull($compiled->promotionFallbackPath());
    }

    public function testGenericRequiredFixtureCompilesPrivacyPolicyOnly(): void
    {
        $inventory = $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php');
        $compiled = $this->makeCompiler(
            policy: require base_path('tests/Fixtures/DeepLinks/project_deep_link_route_policy_generic_required.php'),
            inventory: $inventory,
        )->compile();

        $this->assertSame('required_policy', $compiled->availabilityMode());
        $this->assertSame('/privacy-policy', $compiled->routeDefinition('privacy_policy')['path'] ?? null);
        $this->assertSame([], $compiled->associationPaths());
        $this->assertNull($compiled->promotionFallbackPath());
        $this->assertSame(
            CompanionRouteInventorySnapshot::IDENTITY,
            $compiled->inventorySnapshotIdentity(),
        );
        $this->assertSame(
            CompanionRouteInventorySnapshot::fromInventoryRoutes($inventory)['digest'],
            $compiled->inventorySnapshotDigest(),
        );
    }

    public function testBellugaFixtureCompilesAssociationPromotionAndDeferredMetadata(): void
    {
        $inventory = $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php');
        $compiled = $this->makeCompiler(
            policy: require base_path('tests/Fixtures/DeepLinks/project_deep_link_route_policy_belluga.php'),
            inventory: $inventory,
        )->compile();

        $this->assertSame(['/invite*', '/convites*'], $compiled->associationPaths());
        $this->assertSame('/baixe-o-app', $compiled->promotionFallbackPath());
        $this->assertSame('/auth', $compiled->nestedContinuation()['boundary_path'] ?? null);
        $this->assertSame(['code'], $compiled->deferredCapture()['code_keys'] ?? null);
        $this->assertSame('root_landing', $compiled->canonicalQueryRule()['absent_value_route_id'] ?? null);
        $this->assertSame('/agenda/evento/', $compiled->routeDefinition('event_detail')['path'] ?? null);
        $this->assertSame(
            CompanionRouteInventorySnapshot::IDENTITY,
            $compiled->inventorySnapshotIdentity(),
        );
        $this->assertSame(
            CompanionRouteInventorySnapshot::fromInventoryRoutes($inventory)['digest'],
            $compiled->inventorySnapshotDigest(),
        );
    }

    public function testRequiredPolicyRejectsUnknownPublicShellRouteId(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'missing_binding',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'does_not_exist',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown public-shell route ID');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsLocalExactPathThatOverlapsCompanionOneSegmentRoute(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'partner_detail',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'partner_detail',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                    [
                        'route_id' => 'partner_campaign',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/parceiro/acme',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps one-segment route `partner_detail`');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsDuplicateOneSegmentPrefixesAcrossCompanionAndLocalRoutes(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'partner_detail',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'partner_detail',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                    [
                        'route_id' => 'partner_alias',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'one_segment',
                        'path' => '/parceiro/',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one-segment prefix `/parceiro/` is declared more than once');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsDuplicateCompanionResolvedExactPaths(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'invite_primary',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'invite_primary',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                    [
                        'route_id' => 'invite_duplicate',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'invite_duplicate',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
            ],
            inventory: [
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
                [
                    'route_id' => 'invite_primary',
                    'shape' => 'exact',
                    'path' => '/invite',
                    'kind' => 'public_shell',
                ],
                [
                    'route_id' => 'invite_duplicate',
                    'shape' => 'exact',
                    'path' => '/invite',
                    'kind' => 'public_shell',
                ],
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declared more than once');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsShapeIncompatiblePublicShellRouteRoles(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'partner_detail',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'partner_detail',
                        'roles' => ['continuation', 'canonical_query_source'],
                        'query_keys' => ['slug'],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may only carry the `continuation` role');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsMalformedExactPathLiteral(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'bad_path',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/invite?code=CODE123',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain `?`');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsOversizedQueryKeyList(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'too_many_keys',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/keys',
                        'roles' => ['continuation'],
                        'query_keys' => [
                            'one',
                            'two',
                            'three',
                            'four',
                            'five',
                            'six',
                            'seven',
                            'eight',
                            'nine',
                        ],
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may declare at most 8 unique keys');

        $compiler->compile();
    }

    public function testRequiredPolicyAcceptsExactlySixtyFourRoutes(): void
    {
        $routes = [];
        for ($index = 1; $index <= 64; $index++) {
            $routes[] = [
                'route_id' => sprintf('route_%02d', $index),
                'ingress_requirement' => 'continuation_only',
                'shape' => 'exact',
                'path' => sprintf('/route-%02d', $index),
                'roles' => ['continuation'],
                'query_keys' => [],
            ];
        }

        $compiled = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => $routes,
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        )->compile();

        $this->assertSame('required_policy', $compiled->availabilityMode());
        $this->assertCount(64, $compiled->routes());
        $this->assertSame('/route-64', $compiled->routeDefinition('route_64')['path'] ?? null);
    }

    public function testRequiredPolicyRejectsDeferredCodeKeysWithoutCanonicalQueryRule(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'capture_entry',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/capture',
                        'roles' => ['continuation'],
                        'query_keys' => [],
                    ],
                ],
                'deferred_capture' => [
                    'source_precedence' => 'deferred_payload_then_install_referrer',
                    'code_keys' => ['code'],
                    'target_path_keys' => [],
                    'nested_payload_keys' => [],
                    'store_channel_keys' => [],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deferred_capture.code_keys requires one compatible canonical_query_rules entry');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsDeferredCodeKeysThatOmitCanonicalExternalArgumentAlias(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'invite_source',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/invite-source',
                        'roles' => ['continuation', 'canonical_query_source'],
                        'query_keys' => ['invite_code'],
                    ],
                    [
                        'route_id' => 'invite_target',
                        'ingress_requirement' => 'continuation_only',
                        'shape' => 'exact',
                        'path' => '/invite-target',
                        'roles' => ['continuation', 'canonical_query_target'],
                        'query_keys' => ['invite_code'],
                    ],
                ],
                'canonical_query_rules' => [
                    [
                        'source_route_ids' => ['invite_source'],
                        'target_route_id' => 'invite_target',
                        'absent_value_route_id' => 'root_landing',
                        'query_key' => 'invite_code',
                        'external_argument_key' => 'code',
                        'conflict_strategy' => 'external_wins',
                    ],
                ],
                'deferred_capture' => [
                    'source_precedence' => 'deferred_payload_then_install_referrer',
                    'code_keys' => ['legacy_code'],
                    'target_path_keys' => [],
                    'nested_payload_keys' => [],
                    'store_channel_keys' => [],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deferred_capture.code_keys must include canonical_query_rules.external_argument_key `code`');

        $compiler->compile();
    }

    public function testRequiredPolicyRejectsPromotionFallbackCombinedWithAssociationRole(): void
    {
        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => [
                    [
                        'route_id' => 'promotion_fallback_route',
                        'ingress_requirement' => 'public_shell_required',
                        'public_shell_route_id' => 'promotion_fallback',
                        'roles' => ['continuation', 'promotion_fallback', 'ios_association'],
                        'query_keys' => ['redirect'],
                        'target_query_key' => 'redirect',
                        'association_emission' => 'exact',
                    ],
                ],
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot combine `promotion_fallback` with canonical query or iOS association roles');

        $compiler->compile();
    }

    #[DataProvider('invalidClosedGrammarProvider')]
    public function testRequiredPolicyRejectsRemainingClosedGrammarViolations(
        array $policy,
        string $expectedMessage,
        string $inventoryFixture = 'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
    ): void {
        $compiler = $this->makeCompiler(
            policy: $policy,
            inventory: $this->inventoryFromFixture($inventoryFixture),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $compiler->compile();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2?: string}>
     */
    public static function invalidClosedGrammarProvider(): array
    {
        return [
            'invalid route id grammar' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'Bad Route',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/invalid-route',
                            'roles' => ['continuation'],
                            'query_keys' => [],
                        ],
                    ],
                ],
                'Invalid project deep-link route_id',
            ],
            'zero roles rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'missing_roles',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/missing-roles',
                            'roles' => [],
                            'query_keys' => [],
                        ],
                    ],
                ],
                'must declare 1 to 4 roles',
            ],
            'more than four unique roles rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'too_many_roles',
                            'ingress_requirement' => 'public_shell_required',
                            'public_shell_route_id' => 'invite_landing',
                            'roles' => [
                                'continuation',
                                'promotion_fallback',
                                'canonical_query_source',
                                'canonical_query_target',
                                'ios_association',
                            ],
                            'query_keys' => ['redirect', 'code'],
                            'target_query_key' => 'redirect',
                            'association_emission' => 'exact',
                        ],
                    ],
                ],
                'must declare 1 to 4 unique roles',
            ],
            'multiple promotion fallbacks rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'promotion_primary',
                            'ingress_requirement' => 'public_shell_required',
                            'public_shell_route_id' => 'promotion_fallback',
                            'roles' => ['continuation', 'promotion_fallback'],
                            'query_keys' => ['redirect'],
                            'target_query_key' => 'redirect',
                        ],
                        [
                            'route_id' => 'promotion_secondary',
                            'ingress_requirement' => 'public_shell_required',
                            'public_shell_route_id' => 'map_landing',
                            'roles' => ['continuation', 'promotion_fallback'],
                            'query_keys' => ['redirect'],
                            'target_query_key' => 'redirect',
                        ],
                    ],
                ],
                'Only one project deep-link promotion fallback route is allowed',
            ],
            'invalid association emission rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'invite_association',
                            'ingress_requirement' => 'public_shell_required',
                            'public_shell_route_id' => 'invite_landing',
                            'roles' => ['continuation', 'ios_association'],
                            'query_keys' => ['code'],
                            'association_emission' => 'wildcard',
                        ],
                    ],
                ],
                'Invalid association_emission for `invite_association`',
            ],
            'query key length bound rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'oversized_key',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/oversized-key',
                            'roles' => ['continuation'],
                            'query_keys' => [str_repeat('a', 65)],
                        ],
                    ],
                ],
                'Invalid project deep-link query keys for `oversized_key`',
            ],
            'nested continuation boundary mode rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'auth_boundary',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/auth',
                            'roles' => ['continuation'],
                            'query_keys' => ['redirect'],
                        ],
                    ],
                    'nested_continuation' => [
                        'boundary_path' => '/auth',
                        'boundary_match' => 'descendant_only',
                        'target_query_key' => 'redirect',
                        'max_unwrap_depth' => 1,
                    ],
                ],
                'Unsupported nested_continuation boundary_match',
            ],
            'nested continuation max depth rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'auth_boundary',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/auth',
                            'roles' => ['continuation'],
                            'query_keys' => ['redirect'],
                        ],
                    ],
                    'nested_continuation' => [
                        'boundary_path' => '/auth',
                        'boundary_match' => 'exact',
                        'target_query_key' => 'redirect',
                        'max_unwrap_depth' => 6,
                    ],
                ],
                'nested_continuation.max_unwrap_depth must be between 1 and 5',
            ],
            'canonical query conflict strategy rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'invite_source',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/invite-source',
                            'roles' => ['continuation', 'canonical_query_source'],
                            'query_keys' => ['invite_code'],
                        ],
                        [
                            'route_id' => 'invite_target',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/invite-target',
                            'roles' => ['continuation', 'canonical_query_target'],
                            'query_keys' => ['invite_code'],
                        ],
                    ],
                    'canonical_query_rules' => [
                        [
                            'source_route_ids' => ['invite_source'],
                            'target_route_id' => 'invite_target',
                            'absent_value_route_id' => 'root_landing',
                            'query_key' => 'invite_code',
                            'external_argument_key' => 'code',
                            'conflict_strategy' => 'route_wins',
                        ],
                    ],
                ],
                'Unsupported canonical query conflict strategy `route_wins`',
            ],
            'canonical query unknown target route rejected' => [
                [
                    'version' => 1,
                    'availability' => 'required_policy',
                    'routes' => [
                        [
                            'route_id' => 'invite_source',
                            'ingress_requirement' => 'continuation_only',
                            'shape' => 'exact',
                            'path' => '/invite-source',
                            'roles' => ['continuation', 'canonical_query_source'],
                            'query_keys' => ['invite_code'],
                        ],
                    ],
                    'canonical_query_rules' => [
                        [
                            'source_route_ids' => ['invite_source'],
                            'target_route_id' => 'missing_target',
                            'absent_value_route_id' => 'root_landing',
                            'query_key' => 'invite_code',
                            'external_argument_key' => 'code',
                            'conflict_strategy' => 'external_wins',
                        ],
                    ],
                ],
                'canonical_query_rules references unknown target route `missing_target`',
            ],
        ];
    }

    public function testRequiredPolicyRejectsMoreThanSixtyFourRoutes(): void
    {
        $routes = [];
        for ($index = 1; $index <= 65; $index++) {
            $routes[] = [
                'route_id' => sprintf('route_%02d', $index),
                'ingress_requirement' => 'continuation_only',
                'exact' => sprintf('/route-%02d', $index),
                'roles' => ['continuation'],
                'query_keys' => [],
            ];
        }

        $compiler = $this->makeCompiler(
            policy: [
                'version' => 1,
                'availability' => 'required_policy',
                'routes' => $routes,
            ],
            inventory: $this->inventoryFromFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most 64 routes');

        $compiler->compile();
    }

    /**
     * @param  array<string, mixed>|null  $policy
     * @param  list<array<string, mixed>>  $inventory
     */
    private function makeCompiler(?array $policy, array $inventory): ProjectRoutePolicyCompiler
    {
        return new ProjectRoutePolicyCompiler(
            new class($policy) implements ProjectRoutePolicySourceContract {
                /**
                 * @param  array<string, mixed>|null  $policy
                 */
                public function __construct(
                    private readonly ?array $policy,
                ) {}

                public function currentProjectRoutePolicy(): ?array
                {
                    return $this->policy;
                }
            },
            new class($inventory) implements PublicShellRouteInventorySourceContract {
                /**
                 * @param  list<array<string, mixed>>  $inventory
                 */
                public function __construct(
                    private readonly array $inventory,
                ) {}

                public function currentPublicShellRouteInventory(): array
                {
                    return $this->inventory;
                }
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryFromFixture(string $fixture): array
    {
        $config = require base_path($fixture);

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
}
