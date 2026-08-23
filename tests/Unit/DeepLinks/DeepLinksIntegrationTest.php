<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use App\Integration\DeepLinks\AppLinksIdentifierGatewayAdapter;
use App\Integration\DeepLinks\AppLinksSettingsSourceAdapter;
use App\Integration\DeepLinks\ProjectRoutePolicySourceAdapter;
use App\Integration\DeepLinks\PublicShellRouteInventorySourceAdapter;
use Shared\DeepLinks\Application\CompanionRouteInventorySnapshot;
use Shared\DeepLinks\Application\CompiledProjectRoutePolicy;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;
use Shared\Settings\Contracts\SettingsRegistryContract;
use Tests\TestCase;

class DeepLinksIntegrationTest extends TestCase
{
    public function testAppLinksBindingsAndNamespaceAreRegistered(): void
    {
        $this->assertInstanceOf(
            AppLinksIdentifierGatewayAdapter::class,
            $this->app->make(AppLinksIdentifierGatewayContract::class)
        );

        $this->assertInstanceOf(
            AppLinksSettingsSourceAdapter::class,
            $this->app->make(AppLinksSettingsSourceContract::class)
        );

        $this->assertInstanceOf(
            ProjectRoutePolicySourceAdapter::class,
            $this->app->make(ProjectRoutePolicySourceContract::class)
        );

        $this->assertInstanceOf(
            PublicShellRouteInventorySourceAdapter::class,
            $this->app->make(PublicShellRouteInventorySourceContract::class)
        );

        $compiledPolicy = $this->app->make(CompiledProjectRoutePolicy::class);
        $this->assertInstanceOf(CompiledProjectRoutePolicy::class, $compiledPolicy);
        $this->assertSame(['/invite*', '/convites*'], $compiledPolicy->associationPaths());
        $inventory = $this->app->make(PublicShellRouteInventorySourceContract::class)
            ->currentPublicShellRouteInventory();
        $snapshot = CompanionRouteInventorySnapshot::fromInventoryRoutes($inventory);
        $this->assertSame(CompanionRouteInventorySnapshot::IDENTITY, $compiledPolicy->inventorySnapshotIdentity());
        $this->assertSame($snapshot['digest'], $compiledPolicy->inventorySnapshotDigest());

        $registry = $this->app->make(SettingsRegistryContract::class);
        $this->assertNotNull($registry->find('app_links', 'tenant'));
    }

    public function testNeutralFixtureAdaptersAndCompiledPolicyShareSnapshotDigest(): void
    {
        $policyPath = base_path('tests/Fixtures/DeepLinks/project_deep_link_route_policy_generic_required.php');
        $routePath = base_path('tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php');
        $previousPolicyPath = getenv('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE');
        $previousRoutePath = getenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
        $hadPolicyEnv = array_key_exists('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE', $_ENV);
        $hadRouteEnv = array_key_exists('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE', $_ENV);
        $previousPolicyEnv = $_ENV['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] ?? null;
        $previousRouteEnv = $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] ?? null;
        $hadPolicyServer = array_key_exists('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE', $_SERVER);
        $hadRouteServer = array_key_exists('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE', $_SERVER);
        $previousPolicyServer = $_SERVER['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] ?? null;
        $previousRouteServer = $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] ?? null;

        putenv('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE='.$policyPath);
        putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$routePath);
        $_ENV['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] = $policyPath;
        $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $routePath;
        $_SERVER['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] = $policyPath;
        $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $routePath;
        $this->refreshApplication();

        try {
            $compiledPolicy = $this->app->make(CompiledProjectRoutePolicy::class);
            $inventory = $this->app->make(PublicShellRouteInventorySourceContract::class)
                ->currentPublicShellRouteInventory();
            $snapshot = CompanionRouteInventorySnapshot::fromInventoryRoutes($inventory);

            $this->assertSame('required_policy', $compiledPolicy->availabilityMode());
            $this->assertSame(CompanionRouteInventorySnapshot::IDENTITY, $compiledPolicy->inventorySnapshotIdentity());
            $this->assertSame($snapshot['digest'], $compiledPolicy->inventorySnapshotDigest());
        } finally {
            if ($previousPolicyPath === false) {
                putenv('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE');
            } else {
                putenv('DEEP_LINK_ROUTE_POLICY_CONFIG_FILE='.$previousPolicyPath);
            }
            if ($previousRoutePath === false) {
                putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
            } else {
                putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$previousRoutePath);
            }
            if ($hadPolicyEnv) {
                $_ENV['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] = $previousPolicyEnv;
            } else {
                unset($_ENV['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE']);
            }
            if ($hadRouteEnv) {
                $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $previousRouteEnv;
            } else {
                unset($_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE']);
            }
            if ($hadPolicyServer) {
                $_SERVER['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE'] = $previousPolicyServer;
            } else {
                unset($_SERVER['DEEP_LINK_ROUTE_POLICY_CONFIG_FILE']);
            }
            if ($hadRouteServer) {
                $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $previousRouteServer;
            } else {
                unset($_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE']);
            }
            $this->refreshApplication();
        }
    }
}
