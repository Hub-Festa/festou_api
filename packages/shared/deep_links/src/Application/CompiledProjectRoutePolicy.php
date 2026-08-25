<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Application;

class CompiledProjectRoutePolicy
{
    /**
     * @param  array<string, array<string, mixed>>  $routesById
     * @param  array<string, array<string, mixed>>  $exactRoutesByPath
     * @param  array<int, array<string, mixed>>  $oneSegmentRoutes
     * @param  array<int, string>  $associationPaths
     * @param  array<string, array<string, mixed>>  $inventoryRoutesById
     * @param  list<array{route_id: string, kind: string, canonical_shape: string}>  $inventorySnapshotProjection
     */
    public function __construct(
        private readonly string $availabilityMode,
        private readonly array $routesById,
        private readonly array $exactRoutesByPath,
        private readonly array $oneSegmentRoutes,
        private readonly array $associationPaths,
        private readonly ?string $promotionFallbackPath,
        private readonly ?array $canonicalQueryRule,
        private readonly ?array $nestedContinuation,
        private readonly ?array $deferredCapture,
        private readonly array $inventoryRoutesById,
        private readonly string $inventorySnapshotIdentity,
        private readonly string $inventorySnapshotDigest,
        private readonly array $inventorySnapshotProjection,
    ) {}

    public function availabilityMode(): string
    {
        return $this->availabilityMode;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function routes(): array
    {
        return array_values($this->routesById);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function routeDefinition(string $routeId): ?array
    {
        return $this->routesById[$routeId] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inventoryRoute(string $routeId): ?array
    {
        return $this->inventoryRoutesById[$routeId] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function associationPaths(): array
    {
        return $this->associationPaths;
    }

    public function promotionFallbackPath(): ?string
    {
        return $this->promotionFallbackPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exactRouteForPath(string $path): ?array
    {
        return $this->exactRoutesByPath[$path] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function continuationRouteForPath(string $path): ?array
    {
        $exact = $this->exactRouteForPath($path);
        if ($exact !== null) {
            return $exact;
        }

        foreach ($this->oneSegmentRoutes as $route) {
            $prefix = (string) $route['path'];
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $segment = substr($path, strlen($prefix));
            if ($segment === '' || str_contains($segment, '/')) {
                continue;
            }

            return $route;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function canonicalQueryRule(): ?array
    {
        return $this->canonicalQueryRule;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function nestedContinuation(): ?array
    {
        return $this->nestedContinuation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deferredCapture(): ?array
    {
        return $this->deferredCapture;
    }

    public function inventorySnapshotIdentity(): string
    {
        return $this->inventorySnapshotIdentity;
    }

    public function inventorySnapshotDigest(): string
    {
        return $this->inventorySnapshotDigest;
    }

    /**
     * @return list<array{route_id: string, kind: string, canonical_shape: string}>
     */
    public function inventorySnapshotProjection(): array
    {
        return $this->inventorySnapshotProjection;
    }
}
