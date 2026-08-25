<?php

declare(strict_types=1);

namespace Tests\Unit\PublicWeb;

use App\Application\PublicWeb\ProjectPublicShellRouteRegistry;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Contracts\Container\BindingResolutionException;
use RuntimeException;
use Tests\TestCase;

class ProjectPublicShellRouteRegistryTest extends TestCase
{
    private string $previousConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConfigPath = (string) getenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
    }

    protected function tearDown(): void
    {
        if ($this->previousConfigPath === '') {
            putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE');
            unset($_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'], $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE']);
        } else {
            putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$this->previousConfigPath);
            $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $this->previousConfigPath;
            $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $this->previousConfigPath;
        }

        parent::tearDown();
    }

    public function testLoadsConfiguredBellugaFixtureRouteInventoryExactly(): void
    {
        $registry = $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes.php');

        $definitions = $registry->routeDefinitions();

        $this->assertSame($this->expectedBellugaRouteDefinitions(), $definitions);
    }

    public function testLoadsConfiguredNeutralFixtureRouteInventoryExactly(): void
    {
        $registry = $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_neutral.php');

        $definitions = $registry->routeDefinitions();

        $this->assertSame($this->expectedNeutralRouteDefinitions(), $definitions);
    }

    public function testRejectsDuplicateExtensionIds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate project extension ID');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_duplicate_extensions.php')
            ->routeDefinitions();
    }

    public function testRejectsServiceThatDoesNotImplementMetadataContract(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not implement');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_non_contract_service.php')
            ->routeDefinitions();
    }

    public function testRejectsMissingServiceBinding(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('missing.public_shell.extension');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_missing_binding.php')
            ->routeDefinitions();
    }

    public function testRejectsReservedGenericPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved generic path');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_reserved_path.php')
            ->routeDefinitions();
    }

    public function testRejectsReservedGenericPathFamily(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved generic path family');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_reserved_prefix.php')
            ->routeDefinitions();
    }

    public function testRejectsUnsupportedShapeToExtensionPairing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_unsupported_shape.php')
            ->routeDefinitions();
    }

    public function testRejectsExactAndOneSegmentRouteOverlap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps');

        $this->makeRegistryForFixture('tests/Fixtures/PublicWeb/project_public_shell_routes_overlap.php')
            ->routeDefinitions();
    }

    /**
     * @return list<array<string, string>>
     */
    private function expectedBellugaRouteDefinitions(): array
    {
        return [
            [
                'route_id' => 'discover_landing',
                'shape' => 'exact',
                'path' => '/descobrir',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'map_landing',
                'shape' => 'exact',
                'path' => '/mapa',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'map_poi_landing',
                'shape' => 'exact',
                'path' => '/mapa/poi',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'invite_landing',
                'shape' => 'exact',
                'path' => '/invite',
                'semantic' => 'invite_metadata_test',
            ],
            [
                'route_id' => 'invites_landing',
                'shape' => 'exact',
                'path' => '/convites',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'location_permission',
                'shape' => 'exact',
                'path' => '/location/permission',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'promotion_fallback',
                'shape' => 'exact',
                'path' => '/baixe-o-app',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'partner_detail',
                'shape' => 'one_segment',
                'path' => '/parceiro/',
                'semantic' => 'account_profile_metadata',
            ],
            [
                'route_id' => 'event_detail',
                'shape' => 'one_segment',
                'path' => '/agenda/evento/',
                'semantic' => 'event_metadata',
            ],
            [
                'route_id' => 'static_asset_detail',
                'shape' => 'one_segment',
                'path' => '/static/',
                'semantic' => 'static_asset_metadata_test',
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function expectedNeutralRouteDefinitions(): array
    {
        return [
            [
                'route_id' => 'project_landing',
                'shape' => 'exact',
                'path' => '/project-entry',
                'semantic' => 'shell',
            ],
            [
                'route_id' => 'project_profile',
                'shape' => 'one_segment',
                'path' => '/project-profiles/',
                'semantic' => 'account_profile_metadata',
            ],
            [
                'route_id' => 'project_event',
                'shape' => 'one_segment',
                'path' => '/project-events/',
                'semantic' => 'event_metadata',
            ],
        ];
    }

    private function makeRegistryForFixture(string $fixturePath): ProjectPublicShellRouteRegistry
    {
        putenv('PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE='.$fixturePath);
        $_ENV['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $fixturePath;
        $_SERVER['PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE'] = $fixturePath;

        return new ProjectPublicShellRouteRegistry(
            $this->app,
            $this->app->make(PublicWebMetadataService::class),
        );
    }
}
