<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RuntimeException;

class ProjectPublicShellRouteRegistry
{
    public const SHAPE_EXACT = 'exact';

    public const SHAPE_ONE_SEGMENT = 'one_segment';

    private const BUILTIN_SHELL = 'shell';

    private const BUILTIN_ACCOUNT_PROFILE = 'account_profile_metadata';

    private const BUILTIN_EVENT = 'event_metadata';

    /**
     * @var array<int, string>
     */
    private const BUILTIN_SEMANTICS = [
        self::BUILTIN_SHELL,
        self::BUILTIN_ACCOUNT_PROFILE,
        self::BUILTIN_EVENT,
    ];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SHAPES = [
        self::SHAPE_EXACT,
        self::SHAPE_ONE_SEGMENT,
    ];

    /**
     * @var array<int, string>
     */
    private const RESERVED_GENERIC_EXACT_PATHS = [
        '/',
        '/open-app',
        '/privacy-policy',
        '/.well-known/assetlinks.json',
        '/.well-known/apple-app-site-association',
        '/manifest.json',
        '/favicon.ico',
        '/logo-dark.png',
        '/logo-light.png',
        '/icon-dark.png',
        '/icon-light.png',
        '/index.html',
        '/main.dart.js',
        '/flutter_bootstrap.js',
        '/flutter_service_worker.js',
        '/build_metadata.json',
        '/version.json',
    ];

    /**
     * @var array<int, string>
     */
    private const RESERVED_GENERIC_PREFIX_PATHS = [
        '/.well-known/',
        '/icon/',
        '/assets/',
        '/storage/',
    ];

    private const ROUTE_ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $routeDefinitionsById = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $extensionDefinitionsById = null;

    public function __construct(
        private readonly Container $container,
        private readonly PublicWebMetadataService $metadataService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function routeDefinitions(): array
    {
        $this->loadConfiguration();

        return array_values($this->routeDefinitionsById ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function routeDefinition(string $routeId): array
    {
        $this->loadConfiguration();

        $definition = $this->routeDefinitionsById[$routeId] ?? null;
        if (! is_array($definition)) {
            throw new RuntimeException(sprintf(
                'Unknown project public-shell route ID `%s`.',
                $routeId,
            ));
        }

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    public function metadataForRoute(
        Request $request,
        string $routeId,
        ?string $segment,
    ): array {
        $definition = $this->routeDefinition($routeId);
        $requestedPath = $this->requestedPathForDefinition($request, $definition, $segment);
        $semantic = (string) $definition['semantic'];

        return match ($semantic) {
            self::BUILTIN_SHELL => $this->metadataService->defaultMetadata($requestedPath),
            self::BUILTIN_ACCOUNT_PROFILE => $this->metadataForAccountProfileRoute($segment, $requestedPath),
            self::BUILTIN_EVENT => $this->metadataForEventRoute($segment, $requestedPath),
            default => $this->metadataForExtensionRoute(
                $semantic,
                $request,
                $routeId,
                $requestedPath,
                $segment,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function requestedPathForDefinition(
        Request $request,
        array $definition,
        ?string $segment,
    ): string {
        $requestedUri = trim((string) $request->getRequestUri());
        if ($requestedUri !== '') {
            return $requestedUri;
        }

        $path = (string) $definition['path'];
        if ((string) $definition['shape'] === self::SHAPE_ONE_SEGMENT) {
            $normalizedSegment = trim((string) $segment);
            if ($normalizedSegment === '') {
                throw new RuntimeException(sprintf(
                    'Project route `%s` requires one path segment.',
                    (string) $definition['route_id']
                ));
            }

            return $path.$normalizedSegment;
        }

        return $path;
    }

    public static function configuredPath(): string
    {
        $configured = trim((string) env(
            'PUBLIC_WEB_PROJECT_ROUTE_CONFIG_FILE',
            '/opt/project/laravel/public_shell_routes.php'
        ));
        if ($configured === '') {
            return '/opt/project/laravel/public_shell_routes.php';
        }

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return base_path($configured);
    }

    private function loadConfiguration(): void
    {
        if ($this->routeDefinitionsById !== null && $this->extensionDefinitionsById !== null) {
            return;
        }

        $path = self::configuredPath();
        if (! is_file($path)) {
            $this->routeDefinitionsById = [];
            $this->extensionDefinitionsById = [];

            return;
        }

        $raw = require $path;
        if (! is_array($raw)) {
            throw new RuntimeException(sprintf(
                'Project public-shell route config `%s` must return an array.',
                $path
            ));
        }

        $this->extensionDefinitionsById = $this->normalizeExtensionDefinitions(
            $raw['extensions'] ?? [],
            $path,
        );
        $this->routeDefinitionsById = $this->normalizeRouteDefinitions(
            $raw['routes'] ?? [],
            $this->extensionDefinitionsById,
            $path,
        );
    }

    /**
     * @param  mixed  $rawExtensions
     * @return array<string, array<string, mixed>>
     */
    private function normalizeExtensionDefinitions(
        mixed $rawExtensions,
        string $configPath,
    ): array {
        if ($rawExtensions === null) {
            return [];
        }

        if (! is_array($rawExtensions)) {
            throw new RuntimeException(sprintf(
                'Project public-shell route config `%s` must declare `extensions` as an array.',
                $configPath
            ));
        }

        $normalized = [];
        foreach ($rawExtensions as $index => $rawExtension) {
            if (! is_array($rawExtension)) {
                throw new RuntimeException(sprintf(
                    'Project public-shell route config `%s` contains a non-array extension at index %d.',
                    $configPath,
                    $index,
                ));
            }

            $extensionId = trim((string) ($rawExtension['extension_id'] ?? ''));
            $this->assertStableIdentifier(
                $extensionId,
                sprintf('extension_id at index %d in `%s`', $index, $configPath),
            );

            if (in_array($extensionId, self::BUILTIN_SEMANTICS, true)) {
                throw new RuntimeException(sprintf(
                    'Project extension ID `%s` in `%s` conflicts with a reserved built-in semantic.',
                    $extensionId,
                    $configPath
                ));
            }

            if (array_key_exists($extensionId, $normalized)) {
                throw new RuntimeException(sprintf(
                    'Duplicate project extension ID `%s` in `%s`.',
                    $extensionId,
                    $configPath
                ));
            }

            $serviceContainerId = trim((string) ($rawExtension['service_container_id'] ?? ''));
            if ($serviceContainerId === '') {
                throw new RuntimeException(sprintf(
                    'Project extension `%s` in `%s` must declare a non-empty `service_container_id`.',
                    $extensionId,
                    $configPath
                ));
            }

            $supportedShapes = $rawExtension['supported_shapes'] ?? [];
            if (! is_array($supportedShapes) || $supportedShapes === []) {
                throw new RuntimeException(sprintf(
                    'Project extension `%s` in `%s` must declare a non-empty `supported_shapes` list.',
                    $extensionId,
                    $configPath
                ));
            }

            $normalizedShapes = [];
            foreach ($supportedShapes as $shape) {
                $normalizedShape = trim((string) $shape);
                if (! in_array($normalizedShape, self::ALLOWED_SHAPES, true)) {
                    throw new RuntimeException(sprintf(
                        'Project extension `%s` in `%s` declares unsupported shape `%s`.',
                        $extensionId,
                        $configPath,
                        $normalizedShape
                    ));
                }

                $normalizedShapes[$normalizedShape] = $normalizedShape;
            }

            $resolved = $this->container->make($serviceContainerId);
            if (! $resolved instanceof ProjectPublicShellMetadataExtension) {
                throw new RuntimeException(sprintf(
                    'Project extension `%s` in `%s` resolves `%s`, which does not implement %s.',
                    $extensionId,
                    $configPath,
                    $serviceContainerId,
                    ProjectPublicShellMetadataExtension::class
                ));
            }

            $normalized[$extensionId] = [
                'extension_id' => $extensionId,
                'service_container_id' => $serviceContainerId,
                'supported_shapes' => array_values($normalizedShapes),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $rawRoutes
     * @param  array<string, array<string, mixed>>  $extensionsById
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRouteDefinitions(
        mixed $rawRoutes,
        array $extensionsById,
        string $configPath,
    ): array {
        if ($rawRoutes === null) {
            return [];
        }

        if (! is_array($rawRoutes)) {
            throw new RuntimeException(sprintf(
                'Project public-shell route config `%s` must declare `routes` as an array.',
                $configPath
            ));
        }

        if (count($rawRoutes) > 64) {
            throw new RuntimeException(sprintf(
                'Project public-shell route config `%s` exceeds the 64-route limit.',
                $configPath
            ));
        }

        $normalized = [];
        $pathKeys = [];
        $exactPaths = [];
        $oneSegmentPaths = [];
        foreach ($rawRoutes as $index => $rawRoute) {
            if (! is_array($rawRoute)) {
                throw new RuntimeException(sprintf(
                    'Project public-shell route config `%s` contains a non-array route at index %d.',
                    $configPath,
                    $index,
                ));
            }

            $routeId = trim((string) ($rawRoute['route_id'] ?? ''));
            $this->assertStableIdentifier(
                $routeId,
                sprintf('route_id at index %d in `%s`', $index, $configPath),
            );

            if (array_key_exists($routeId, $normalized)) {
                throw new RuntimeException(sprintf(
                    'Duplicate project route ID `%s` in `%s`.',
                    $routeId,
                    $configPath
                ));
            }

            $shape = trim((string) ($rawRoute['shape'] ?? ''));
            if (! in_array($shape, self::ALLOWED_SHAPES, true)) {
                throw new RuntimeException(sprintf(
                    'Project route `%s` in `%s` declares unsupported shape `%s`.',
                    $routeId,
                    $configPath,
                    $shape
                ));
            }

            $path = $this->normalizeRoutePath(
                trim((string) ($rawRoute['path'] ?? '')),
                $shape,
                $routeId,
                $configPath,
            );

            $pathKey = $shape.'|'.$path;
            if (array_key_exists($pathKey, $pathKeys)) {
                throw new RuntimeException(sprintf(
                    'Project route `%s` in `%s` duplicates path/shape `%s` already used by `%s`.',
                    $routeId,
                    $configPath,
                    $pathKey,
                    $pathKeys[$pathKey]
                ));
            }
            $pathKeys[$pathKey] = $routeId;
            $this->assertNoRoutePathOverlap(
                $routeId,
                $shape,
                $path,
                $exactPaths,
                $oneSegmentPaths,
                $configPath,
            );

            $semantic = trim((string) ($rawRoute['semantic'] ?? ''));
            if ($semantic === '') {
                throw new RuntimeException(sprintf(
                    'Project route `%s` in `%s` must declare a non-empty `semantic`.',
                    $routeId,
                    $configPath
                ));
            }

            if (! in_array($semantic, self::BUILTIN_SEMANTICS, true)) {
                $extension = $extensionsById[$semantic] ?? null;
                if (! is_array($extension)) {
                    throw new RuntimeException(sprintf(
                        'Project route `%s` in `%s` references unknown extension semantic `%s`.',
                        $routeId,
                        $configPath,
                        $semantic
                    ));
                }

                if (! in_array($shape, $extension['supported_shapes'], true)) {
                    throw new RuntimeException(sprintf(
                        'Project route `%s` in `%s` uses shape `%s`, which extension `%s` does not support.',
                        $routeId,
                        $configPath,
                        $shape,
                        $semantic
                    ));
                }
            }

            $normalized[$routeId] = [
                'route_id' => $routeId,
                'shape' => $shape,
                'path' => $path,
                'semantic' => $semantic,
            ];

            if ($shape === self::SHAPE_EXACT) {
                $exactPaths[$routeId] = $path;
            } else {
                $oneSegmentPaths[$routeId] = $path;
            }
        }

        return $normalized;
    }

    private function assertStableIdentifier(string $value, string $context): void
    {
        if ($value === '' || preg_match(self::ROUTE_ID_PATTERN, $value) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid stable identifier `%s` for %s. Expected pattern `%s`.',
                $value,
                $context,
                trim(self::ROUTE_ID_PATTERN, '/')
            ));
        }
    }

    private function normalizeRoutePath(
        string $path,
        string $shape,
        string $routeId,
        string $configPath,
    ): string {
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new RuntimeException(sprintf(
                'Project route `%s` in `%s` must use an absolute path or prefix.',
                $routeId,
                $configPath
            ));
        }

        if (preg_match('/[\s?#{}]/', $path) === 1) {
            throw new RuntimeException(sprintf(
                'Project route `%s` in `%s` contains unsupported characters in path `%s`.',
                $routeId,
                $configPath,
                $path
            ));
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($shape === self::SHAPE_EXACT) {
            if ($path !== '/' && str_ends_with($path, '/')) {
                throw new RuntimeException(sprintf(
                    'Exact project route `%s` in `%s` must not end with `/`.',
                    $routeId,
                    $configPath
                ));
            }

            if (in_array($path, self::RESERVED_GENERIC_EXACT_PATHS, true)) {
                throw new RuntimeException(sprintf(
                    'Exact project route `%s` in `%s` cannot claim reserved generic path `%s`.',
                    $routeId,
                    $configPath,
                    $path
                ));
            }

            foreach (self::RESERVED_GENERIC_PREFIX_PATHS as $reservedPrefix) {
                if (! str_starts_with($path, $reservedPrefix)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Exact project route `%s` in `%s` cannot claim reserved generic path family `%s` via `%s`.',
                    $routeId,
                    $configPath,
                    $reservedPrefix,
                    $path
                ));
            }

            if ($segments === []) {
                throw new RuntimeException(sprintf(
                    'Exact project route `%s` in `%s` cannot be the root landing path.',
                    $routeId,
                    $configPath
                ));
            }

            return $path;
        }

        if ($path === '/' || ! str_ends_with($path, '/')) {
            throw new RuntimeException(sprintf(
                'One-segment project route `%s` in `%s` must end with `/` and cannot be `/`.',
                $routeId,
                $configPath
            ));
        }

        if ($segments === []) {
            throw new RuntimeException(sprintf(
                'One-segment project route `%s` in `%s` must declare a non-empty prefix.',
                $routeId,
                $configPath
            ));
        }

        foreach (self::RESERVED_GENERIC_PREFIX_PATHS as $reservedPrefix) {
            if (str_starts_with($path, $reservedPrefix) || str_starts_with($reservedPrefix, $path)) {
                throw new RuntimeException(sprintf(
                    'One-segment project route `%s` in `%s` cannot claim reserved generic path family `%s` via `%s`.',
                    $routeId,
                    $configPath,
                    $reservedPrefix,
                    $path
                ));
            }
        }

        foreach (self::RESERVED_GENERIC_EXACT_PATHS as $reservedPath) {
            if (! str_starts_with($reservedPath, $path)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'One-segment project route `%s` in `%s` cannot claim reserved generic path family via `%s` because it would capture `%s`.',
                $routeId,
                $configPath,
                $path,
                $reservedPath
            ));
        }

        return $path;
    }

    /**
     * @param  array<string, string>  $exactPaths
     * @param  array<string, string>  $oneSegmentPaths
     */
    private function assertNoRoutePathOverlap(
        string $routeId,
        string $shape,
        string $path,
        array $exactPaths,
        array $oneSegmentPaths,
        string $configPath,
    ): void {
        if ($shape === self::SHAPE_EXACT) {
            foreach ($oneSegmentPaths as $otherRouteId => $otherPath) {
                if (! $this->exactPathMatchesOneSegmentPrefix($path, $otherPath)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Project route `%s` in `%s` overlaps one-segment route `%s` because exact path `%s` is matched by prefix `%s`.',
                    $routeId,
                    $configPath,
                    $otherRouteId,
                    $path,
                    $otherPath
                ));
            }

            return;
        }

        foreach ($exactPaths as $otherRouteId => $otherPath) {
            if (! $this->exactPathMatchesOneSegmentPrefix($otherPath, $path)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Project route `%s` in `%s` overlaps exact route `%s` because prefix `%s` would also match `%s`.',
                $routeId,
                $configPath,
                $otherRouteId,
                $path,
                $otherPath
            ));
        }
    }

    private function exactPathMatchesOneSegmentPrefix(string $exactPath, string $prefixPath): bool
    {
        if (! str_starts_with($exactPath, $prefixPath)) {
            return false;
        }

        $segment = substr($exactPath, strlen($prefixPath));

        return $segment !== '' && ! str_contains($segment, '/');
    }

    /**
     * @return array<string, string>
     */
    private function metadataForAccountProfileRoute(?string $segment, string $requestedPath): array
    {
        $slug = trim((string) $segment);
        if ($slug === '') {
            throw new RuntimeException('Account-profile metadata routes require one slug segment.');
        }

        return $this->metadataService->accountProfileMetadata($slug, $requestedPath);
    }

    /**
     * @return array<string, string>
     */
    private function metadataForEventRoute(?string $segment, string $requestedPath): array
    {
        $slug = trim((string) $segment);
        if ($slug === '') {
            throw new RuntimeException('Event metadata routes require one slug segment.');
        }

        return $this->metadataService->eventMetadata($slug, $requestedPath);
    }

    /**
     * @return array<string, string>
     */
    private function metadataForExtensionRoute(
        string $extensionId,
        Request $request,
        string $routeId,
        string $requestedPath,
        ?string $segment,
    ): array {
        $this->loadConfiguration();

        $extension = $this->extensionDefinitionsById[$extensionId] ?? null;
        if (! is_array($extension)) {
            throw new RuntimeException(sprintf(
                'Unknown project public-shell extension `%s` for route `%s`.',
                $extensionId,
                $routeId
            ));
        }

        $resolved = $this->container->make((string) $extension['service_container_id']);
        if (! $resolved instanceof ProjectPublicShellMetadataExtension) {
            throw new RuntimeException(sprintf(
                'Project public-shell extension `%s` no longer resolves a valid metadata extension service.',
                $extensionId
            ));
        }

        return $resolved->metadataFor(
            $request,
            $routeId,
            $requestedPath,
            $segment === null ? null : trim($segment),
        );
    }
}
