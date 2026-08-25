<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use Shared\DeepLinks\Application\ProjectRoutePolicyCompiler;
use Shared\DeepLinks\Application\WebToAppPromotionService;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;
use Tests\TestCase;

class WebToAppPromotionServiceTest extends TestCase
{
    public function testNeutralPolicyFailsClosedForProjectSpecificInvitePaths(): void
    {
        $service = $this->makeServiceForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_neutral.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );

        $this->assertSame('/', $service->normalizeTargetPath('/invite?code=CODE123'));
        $this->assertSame('/', $service->normalizeTargetPath('/agenda/evento/forro?occurrence=occ-1'));
    }

    public function testGenericRequiredPolicyAllowsOnlyDeclaredNeutralContinuation(): void
    {
        $service = $this->makeServiceForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_generic_required.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php',
        );

        $this->assertSame('/privacy-policy', $service->normalizeTargetPath('/privacy-policy'));
        $this->assertSame('/', $service->normalizeTargetPath('/project-entry'));
    }

    public function testBellugaPolicyCanonicalizesInvitesAliasToTargetRoute(): void
    {
        $service = $this->makeServiceForPolicyFixture(
            'tests/Fixtures/DeepLinks/project_deep_link_route_policy_belluga.php',
            'tests/Fixtures/PublicWeb/project_public_shell_routes.php',
        );

        $this->assertSame('/invite?code=CODE123', $service->normalizeTargetPath('/convites?code=CODE123'));
        $this->assertSame('/invite?code=CODE123', $service->normalizeTargetPath('/invite?code=CODE123'));
    }

    private function makeServiceForPolicyFixture(string $policyFixture, string $inventoryFixture): WebToAppPromotionService
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
            $compiledPolicy,
        );
    }
}
