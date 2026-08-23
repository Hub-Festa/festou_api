<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use App\Application\PublicWeb\ProjectPublicShellRouteRegistry;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;

class PublicShellRouteInventorySourceAdapter implements PublicShellRouteInventorySourceContract
{
    public function __construct(
        private readonly ProjectPublicShellRouteRegistry $registry,
    ) {}

    public function currentPublicShellRouteInventory(): array
    {
        return array_merge([
            [
                'route_id' => 'root_landing',
                'shape' => ProjectPublicShellRouteRegistry::SHAPE_EXACT,
                'path' => '/',
                'kind' => 'generic_builtin',
            ],
            [
                'route_id' => 'privacy_policy',
                'shape' => ProjectPublicShellRouteRegistry::SHAPE_EXACT,
                'path' => '/privacy-policy',
                'kind' => 'generic_builtin',
            ],
        ], $this->registry->routeDefinitions());
    }
}
