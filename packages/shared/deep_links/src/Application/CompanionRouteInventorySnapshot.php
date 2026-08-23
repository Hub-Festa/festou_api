<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Application;

use RuntimeException;

final class CompanionRouteInventorySnapshot
{
    public const string IDENTITY = 'companion_route_inventory_projection_v1';

    /**
     * @param  list<array<string, mixed>>  $inventoryRoutes
     * @return array{identity: string, digest: string, projection: list<array{route_id: string, kind: string, canonical_shape: string}>}
     */
    public static function fromInventoryRoutes(array $inventoryRoutes): array
    {
        $projection = [];

        foreach ($inventoryRoutes as $index => $route) {
            if (! is_array($route)) {
                throw new RuntimeException(sprintf(
                    'Companion route inventory projection entry at index %d must be an array.',
                    $index,
                ));
            }

            $routeId = trim((string) ($route['route_id'] ?? ''));
            $kind = trim((string) ($route['kind'] ?? 'public_shell'));
            $shape = trim((string) ($route['shape'] ?? ''));
            $path = trim((string) ($route['path'] ?? ''));

            if ($routeId === '' || $shape === '' || $path === '') {
                throw new RuntimeException(sprintf(
                    'Companion route inventory projection entry at index %d is incomplete.',
                    $index,
                ));
            }

            $projection[] = [
                'route_id' => $routeId,
                'kind' => $kind,
                'canonical_shape' => self::canonicalShape($shape, $path),
            ];
        }

        usort($projection, static function (array $left, array $right): int {
            return [$left['route_id'], $left['kind'], $left['canonical_shape']]
                <=> [$right['route_id'], $right['kind'], $right['canonical_shape']];
        });

        $json = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'identity' => self::IDENTITY,
            'digest' => hash('sha256', $json),
            'projection' => $projection,
        ];
    }

    private static function canonicalShape(string $shape, string $path): string
    {
        return match ($shape) {
            ProjectRoutePolicyCompiler::SHAPE_EXACT => 'exact:'.$path,
            ProjectRoutePolicyCompiler::SHAPE_ONE_SEGMENT => 'one_segment:'.$path.'{segment}',
            default => throw new RuntimeException(sprintf(
                'Unsupported companion route inventory shape `%s`.',
                $shape,
            )),
        };
    }
}
