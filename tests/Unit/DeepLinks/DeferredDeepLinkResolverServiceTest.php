<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use PHPUnit\Framework\Attributes\DataProvider;
use Shared\DeepLinks\Application\DeferredDeepLinkResolverService;
use Shared\DeepLinks\Application\ProjectRoutePolicyCompiler;
use Shared\DeepLinks\Application\WebToAppPromotionService;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;
use Tests\TestCase;

class DeferredDeepLinkResolverServiceTest extends TestCase
{
    public function testNeutralPolicyFailsClosedForDeferredCapture(): void
    {
        $resolver = $this->makeResolverForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_neutral.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );

        $result = $resolver->resolve('code=CODE123', null, 'web');

        $this->assertSame('not_captured', $result['status']);
        $this->assertSame('deferred_capture_unconfigured', $result['failure_reason']);
        $this->assertSame('/', $result['target_path']);
    }

    public function testGenericRequiredPolicyFailsClosedForDeferredCapture(): void
    {
        $resolver = $this->makeResolverForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_generic_required.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php',
        );

        $result = $resolver->resolve(null, 'target_path=%2Fprivacy-policy&utm_source=organic', null);

        $this->assertSame('not_captured', $result['status']);
        $this->assertSame('deferred_capture_unconfigured', $result['failure_reason']);
    }

    public function testBellugaPolicyCapturesNestedDeferredCode(): void
    {
        $resolver = $this->makeResolverForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_belluga.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );

        $result = $resolver->resolve('link=%2Finvite%3Fcode%3DCODE999&utm_source=influencer', null, null);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('CODE999', $result['code']);
        $this->assertSame('/invite?code=CODE999', $result['target_path']);
        $this->assertSame('influencer', $result['store_channel']);
    }

    #[DataProvider('bellugaDeferredCaptureMatrixProvider')]
    public function testBellugaPolicyRespectsDeferredCaptureOutcomeMatrix(
        ?string $deferredPayload,
        ?string $installReferrer,
        ?string $fallbackStoreChannel,
        array $expected,
    ): void {
        $resolver = $this->makeResolverForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_belluga.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );

        $result = $resolver->resolve($deferredPayload, $installReferrer, $fallbackStoreChannel);

        $this->assertSame($expected['status'], $result['status']);
        $this->assertSame($expected['code'], $result['code']);
        $this->assertSame($expected['target_path'], $result['target_path']);
        $this->assertSame($expected['store_channel'], $result['store_channel']);
        $this->assertSame($expected['failure_reason'], $result['failure_reason']);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: array{status: string, code: ?string, target_path: string, store_channel: ?string, failure_reason: ?string}}>
     */
    public static function bellugaDeferredCaptureMatrixProvider(): array
    {
        $eventTarget = 'target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1&utm_source=organic';
        $partnerTarget = rawurlencode('target_path=%2Fparceiro%2Facme');
        $oversizedTarget = rawurlencode('/'.str_repeat('a', 2048));

        return [
            'deferred payload wins over install referrer' => [
                'code=CODE123&store_channel=paid_social',
                $eventTarget,
                null,
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => 'paid_social',
                    'failure_reason' => null,
                ],
            ],
            'install referrer captures target path when deferred payload is absent' => [
                null,
                $eventTarget,
                null,
                [
                    'status' => 'captured',
                    'code' => null,
                    'target_path' => '/agenda/evento/forro?occurrence=occ-1',
                    'store_channel' => 'organic',
                    'failure_reason' => null,
                ],
            ],
            'valid code short circuits direct target path' => [
                'code=CODE123&target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'valid code short circuits malformed direct target path' => [
                'code=CODE123&target_path=%25ZZ',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'blank code collapses to target path' => [
                'code=%20%20&target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => null,
                    'target_path' => '/agenda/evento/forro?occurrence=occ-1',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'malformed direct code fails the source closed' => [
                'code=%25BAD&target_path=%2Fagenda%2Fevento%2Fforro%3Foccurrence%3Docc-1',
                null,
                null,
                [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => null,
                    'failure_reason' => 'source_invalid',
                ],
            ],
            'undeclared auxiliary keys do not affect code capture' => [
                'code=CODE123&gclid=123',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'nested payload captures code when direct classes are absent' => [
                'link=%2Finvite%3Fcode%3DABC123',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => 'ABC123',
                    'target_path' => '/invite?code=ABC123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'blank code collapses to nested code' => [
                'code=%20%20&link=%2Finvite%3Fcode%3DABC123',
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => 'ABC123',
                    'target_path' => '/invite?code=ABC123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'collapse to absence target path falls through to nested target path' => [
                'target_path=%2F%2Fevil.example%2Fdrop&link='.$partnerTarget,
                null,
                null,
                [
                    'status' => 'captured',
                    'code' => null,
                    'target_path' => '/parceiro/acme',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
            'oversized direct target path is source invalid and does not fall through' => [
                'target_path='.$oversizedTarget.'&link=target_path%3D'.$partnerTarget,
                null,
                null,
                [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => null,
                    'failure_reason' => 'source_invalid',
                ],
            ],
            'conflicting direct code aliases fail closed' => [
                'code=ABC123&code=XYZ999&link=%2Finvite%3Fcode%3DABC123',
                null,
                null,
                [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => null,
                    'failure_reason' => 'source_invalid',
                ],
            ],
            'malformed child target path fails closed' => [
                'link=target_path%3D%25ZZ',
                null,
                null,
                [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => null,
                    'failure_reason' => 'source_invalid',
                ],
            ],
            'overencoded child target path fails closed' => [
                'link=target_path%3D%2525252Fparceiro%2525252Facme',
                null,
                null,
                [
                    'status' => 'not_captured',
                    'code' => null,
                    'target_path' => '/',
                    'store_channel' => null,
                    'failure_reason' => 'source_invalid',
                ],
            ],
            'host fallback store channel is normalized as metadata only' => [
                'code=CODE123',
                null,
                'App_Store',
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => 'app_store',
                    'failure_reason' => null,
                ],
            ],
            'invalid host fallback store channel collapses to absence' => [
                'code=CODE123',
                null,
                'Bad Channel!',
                [
                    'status' => 'captured',
                    'code' => 'CODE123',
                    'target_path' => '/invite?code=CODE123',
                    'store_channel' => null,
                    'failure_reason' => null,
                ],
            ],
        ];
    }

    private function makeResolverForPolicyFixture(string $policyFixture, string $inventoryFixture): DeferredDeepLinkResolverService
    {
        $compiledPolicy = (new ProjectRoutePolicyCompiler(
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

        $promotionService = new WebToAppPromotionService(
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
            $compiledPolicy,
        );

        return new DeferredDeepLinkResolverService(
            $compiledPolicy,
            $promotionService,
        );
    }
}
