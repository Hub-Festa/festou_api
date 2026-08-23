<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use App\Http\Controllers\TenantPublicShellController;
use Illuminate\Support\Facades\Route;

class ProjectPublicShellRouteRegistrar
{
    public function __construct(
        private readonly ProjectPublicShellRouteRegistry $registry,
    ) {}

    public function register(): void
    {
        foreach ($this->registry->routeDefinitions() as $definition) {
            $route = Route::get(
                $this->uriForDefinition($definition),
                [TenantPublicShellController::class, 'projectRoute']
            )->defaults('project_public_shell_route_id', $definition['route_id'])
                ->name('project_public_shell.'.$definition['route_id']);

            if ((string) $definition['shape'] === ProjectPublicShellRouteRegistry::SHAPE_ONE_SEGMENT) {
                $route->where('project_public_shell_segment', '[^/]+');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function uriForDefinition(array $definition): string
    {
        $path = (string) $definition['path'];
        if ((string) $definition['shape'] === ProjectPublicShellRouteRegistry::SHAPE_EXACT) {
            return $path;
        }

        return rtrim($path, '/').'/{project_public_shell_segment}';
    }
}
