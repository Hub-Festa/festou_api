<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Application;

use RuntimeException;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;

class ProjectRoutePolicyCompiler
{
    public const string AVAILABILITY_REQUIRED_POLICY = 'required_policy';

    public const string AVAILABILITY_EXPLICIT_NEUTRAL_OPT_OUT = 'explicit_neutral_opt_out';

    public const string INGRESS_PUBLIC_SHELL_REQUIRED = 'public_shell_required';

    public const string INGRESS_CONTINUATION_ONLY = 'continuation_only';

    public const string SHAPE_EXACT = 'exact';

    public const string SHAPE_ONE_SEGMENT = 'one_segment';

    public const string ROLE_CONTINUATION = 'continuation';

    public const string ROLE_PROMOTION_FALLBACK = 'promotion_fallback';

    public const string ROLE_CANONICAL_QUERY_SOURCE = 'canonical_query_source';

    public const string ROLE_CANONICAL_QUERY_TARGET = 'canonical_query_target';

    public const string ROLE_IOS_ASSOCIATION = 'ios_association';

    private const string ASSOCIATION_EMISSION_EXACT = 'exact';

    private const string ASSOCIATION_EMISSION_LITERAL_SUFFIX_WILDCARD = 'literal_suffix_wildcard';

    private const string NESTED_BOUNDARY_MATCH_EXACT = 'exact';

    private const string NESTED_BOUNDARY_MATCH_EXACT_OR_DESCENDANT = 'exact_or_descendant';

    private const string CANONICAL_CONFLICT_EXTERNAL_WINS = 'external_wins';

    private const string CANONICAL_CONFLICT_REJECT = 'reject';

    private const string DEFERRED_SOURCE_PRECEDENCE = 'deferred_payload_then_install_referrer';

    private const string ROUTE_ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    private const string QUERY_KEY_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    /**
     * @var array<int, string>
     */
    private const ROLE_SET = [
        self::ROLE_CONTINUATION,
        self::ROLE_PROMOTION_FALLBACK,
        self::ROLE_CANONICAL_QUERY_SOURCE,
        self::ROLE_CANONICAL_QUERY_TARGET,
        self::ROLE_IOS_ASSOCIATION,
    ];

    public function __construct(
        private readonly ProjectRoutePolicySourceContract $source,
        private readonly PublicShellRouteInventorySourceContract $inventorySource,
    ) {}

    public function compile(): CompiledProjectRoutePolicy
    {
        $inventoryRoutesById = $this->normalizeInventory(
            $this->inventorySource->currentPublicShellRouteInventory()
        );
        $inventorySnapshot = CompanionRouteInventorySnapshot::fromInventoryRoutes(
            array_values($inventoryRoutesById),
        );

        $rawPolicy = $this->source->currentProjectRoutePolicy();
        if ($rawPolicy === null) {
            return $this->compiledNeutralPolicy($inventoryRoutesById, $inventorySnapshot);
        }

        if (! is_array($rawPolicy)) {
            throw new RuntimeException('Project deep-link route policy source must return an array or null.');
        }

        $availability = trim((string) ($rawPolicy['availability'] ?? ''));
        if ($availability === self::AVAILABILITY_EXPLICIT_NEUTRAL_OPT_OUT) {
            $this->assertVersionOne($rawPolicy, 'project deep-link route policy');
            $this->assertNeutralOptOutIsEmpty($rawPolicy);

            return $this->compiledNeutralPolicy($inventoryRoutesById, $inventorySnapshot);
        }

        if ($availability !== self::AVAILABILITY_REQUIRED_POLICY) {
            throw new RuntimeException(sprintf(
                'Unsupported project deep-link route policy availability `%s`.',
                $availability
            ));
        }

        $this->assertVersionOne($rawPolicy, 'project deep-link route policy');

        $rawRoutes = $rawPolicy['routes'] ?? null;
        if (! is_array($rawRoutes) || $rawRoutes === []) {
            throw new RuntimeException('A required project deep-link route policy must declare at least one route.');
        }
        if (count($rawRoutes) > 64) {
            throw new RuntimeException('Project deep-link route policy may declare at most 64 routes.');
        }

        $routesById = [];
        $exactRoutesByPath = [];
        $oneSegmentRoutes = [];
        $associationPaths = [];
        $promotionFallbackPath = null;

        foreach ($rawRoutes as $index => $rawRoute) {
            if (! is_array($rawRoute)) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route policy route at index %d must be an array.',
                    $index
                ));
            }

            $route = $this->normalizeRoute(
                rawRoute: $rawRoute,
                index: (int) $index,
                inventoryRoutesById: $inventoryRoutesById,
            );

            $routeId = (string) $route['route_id'];
            if (isset($routesById[$routeId])) {
                throw new RuntimeException(sprintf(
                    'Duplicate project deep-link route ID `%s`.',
                    $routeId
                ));
            }

            $this->assertNoRouteOverlap(
                route: $route,
                exactRoutesByPath: $exactRoutesByPath,
                oneSegmentRoutes: $oneSegmentRoutes,
            );

            $routesById[$routeId] = $route;

            if ((string) $route['shape'] === self::SHAPE_EXACT) {
                $exactRoutesByPath[(string) $route['path']] = $route;
            } else {
                $oneSegmentRoutes[] = $route;
            }

            if (in_array(self::ROLE_IOS_ASSOCIATION, $route['roles'], true)) {
                $associationPaths[] = $this->associationPathForRoute($route);
            }

            if (in_array(self::ROLE_PROMOTION_FALLBACK, $route['roles'], true)) {
                if ($promotionFallbackPath !== null) {
                    throw new RuntimeException('Only one project deep-link promotion fallback route is allowed.');
                }

                $promotionFallbackPath = (string) $route['path'];
            }
        }

        usort($oneSegmentRoutes, static function (array $left, array $right): int {
            $pathCompare = strlen((string) $right['path']) <=> strlen((string) $left['path']);
            if ($pathCompare !== 0) {
                return $pathCompare;
            }

            return strcmp((string) $left['route_id'], (string) $right['route_id']);
        });

        $canonicalQueryRule = $this->normalizeCanonicalQueryRule(
            rawRules: $rawPolicy['canonical_query_rules'] ?? null,
            routesById: $routesById,
            inventoryRoutesById: $inventoryRoutesById,
        );
        $nestedContinuation = $this->normalizeNestedContinuation(
            rawRule: $rawPolicy['nested_continuation'] ?? null,
            exactRoutesByPath: $exactRoutesByPath,
        );
        $deferredCapture = $this->normalizeDeferredCapture(
            rawRule: $rawPolicy['deferred_capture'] ?? null,
            canonicalQueryRule: $canonicalQueryRule,
        );

        return new CompiledProjectRoutePolicy(
            availabilityMode: self::AVAILABILITY_REQUIRED_POLICY,
            routesById: $routesById,
            exactRoutesByPath: $exactRoutesByPath,
            oneSegmentRoutes: $oneSegmentRoutes,
            associationPaths: array_values(array_unique($associationPaths)),
            promotionFallbackPath: $promotionFallbackPath,
            canonicalQueryRule: $canonicalQueryRule,
            nestedContinuation: $nestedContinuation,
            deferredCapture: $deferredCapture,
            inventoryRoutesById: $inventoryRoutesById,
            inventorySnapshotIdentity: $inventorySnapshot['identity'],
            inventorySnapshotDigest: $inventorySnapshot['digest'],
            inventorySnapshotProjection: $inventorySnapshot['projection'],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventoryRoutesById
     * @param  array{identity: string, digest: string, projection: list<array{route_id: string, kind: string, canonical_shape: string}>}  $inventorySnapshot
     */
    private function compiledNeutralPolicy(
        array $inventoryRoutesById,
        array $inventorySnapshot,
    ): CompiledProjectRoutePolicy
    {
        return new CompiledProjectRoutePolicy(
            availabilityMode: self::AVAILABILITY_EXPLICIT_NEUTRAL_OPT_OUT,
            routesById: [],
            exactRoutesByPath: [],
            oneSegmentRoutes: [],
            associationPaths: [],
            promotionFallbackPath: null,
            canonicalQueryRule: null,
            nestedContinuation: null,
            deferredCapture: null,
            inventoryRoutesById: $inventoryRoutesById,
            inventorySnapshotIdentity: $inventorySnapshot['identity'],
            inventorySnapshotDigest: $inventorySnapshot['digest'],
            inventorySnapshotProjection: $inventorySnapshot['projection'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rawInventory
     * @return array<string, array<string, mixed>>
     */
    private function normalizeInventory(array $rawInventory): array
    {
        $inventoryRoutesById = [];

        foreach ($rawInventory as $index => $rawRoute) {
            if (! is_array($rawRoute)) {
                throw new RuntimeException(sprintf(
                    'Public-shell route inventory entry at index %d must be an array.',
                    $index
                ));
            }

            $routeId = $this->normalizeRouteId(
                $rawRoute['route_id'] ?? null,
                sprintf('public-shell inventory route_id at index %d', $index),
            );
            if (isset($inventoryRoutesById[$routeId])) {
                throw new RuntimeException(sprintf(
                    'Duplicate public-shell inventory route ID `%s`.',
                    $routeId
                ));
            }

            $shape = $this->normalizeShape(
                $rawRoute['shape'] ?? null,
                sprintf('public-shell inventory shape for `%s`', $routeId),
            );
            $path = $shape === self::SHAPE_EXACT
                ? $this->normalizeExactPathLiteral(
                    $rawRoute['path'] ?? null,
                    sprintf('public-shell inventory exact path for `%s`', $routeId),
                    allowRoot: true,
                )
                : $this->normalizeOneSegmentPrefix(
                    $rawRoute['path'] ?? null,
                    sprintf('public-shell inventory one-segment prefix for `%s`', $routeId),
                );

            $inventoryRoutesById[$routeId] = [
                'route_id' => $routeId,
                'shape' => $shape,
                'path' => $path,
                'kind' => trim((string) ($rawRoute['kind'] ?? 'public_shell')),
            ];
        }

        return $inventoryRoutesById;
    }

    /**
     * @param  array<string, mixed>  $rawRoute
     * @param  array<string, array<string, mixed>>  $inventoryRoutesById
     * @return array<string, mixed>
     */
    private function normalizeRoute(array $rawRoute, int $index, array $inventoryRoutesById): array
    {
        $routeId = $this->normalizeRouteId(
            $rawRoute['route_id'] ?? null,
            sprintf('project deep-link route_id at index %d', $index),
        );
        $ingressRequirement = $this->normalizeIngressRequirement(
            $rawRoute['ingress_requirement'] ?? null,
            sprintf('project deep-link ingress requirement for `%s`', $routeId),
        );
        $roles = $this->normalizeRoles(
            $rawRoute['roles'] ?? null,
            sprintf('project deep-link roles for `%s`', $routeId),
        );
        $queryKeys = $this->normalizeQueryKeys(
            $rawRoute['query_keys'] ?? [],
            sprintf('project deep-link query keys for `%s`', $routeId),
            maxCount: 8,
        );

        if (! in_array(self::ROLE_CONTINUATION, $roles, true)) {
            throw new RuntimeException(sprintf(
                'Project deep-link route `%s` must declare the `continuation` role.',
                $routeId
            ));
        }

        if (
            in_array(self::ROLE_CANONICAL_QUERY_SOURCE, $roles, true)
            && in_array(self::ROLE_CANONICAL_QUERY_TARGET, $roles, true)
        ) {
            throw new RuntimeException(sprintf(
                'Project deep-link route `%s` cannot be both canonical query source and target.',
                $routeId
            ));
        }

        $route = [
            'route_id' => $routeId,
            'ingress_requirement' => $ingressRequirement,
            'roles' => $roles,
            'query_keys' => $queryKeys,
        ];

        if ($ingressRequirement === self::INGRESS_PUBLIC_SHELL_REQUIRED) {
            if (array_key_exists('path', $rawRoute) || array_key_exists('shape', $rawRoute)) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` must not restate local path or shape when using `public_shell_required`.',
                    $routeId
                ));
            }

            $publicShellRouteId = $this->normalizeRouteId(
                $rawRoute['public_shell_route_id'] ?? null,
                sprintf('public_shell_route_id for `%s`', $routeId),
            );
            $inventoryRoute = $inventoryRoutesById[$publicShellRouteId] ?? null;
            if ($inventoryRoute === null) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` references unknown public-shell route ID `%s`.',
                    $routeId,
                    $publicShellRouteId
                ));
            }

            $route['public_shell_route_id'] = $publicShellRouteId;
            $route['shape'] = (string) $inventoryRoute['shape'];
            $route['path'] = (string) $inventoryRoute['path'];
        } else {
            if (array_key_exists('public_shell_route_id', $rawRoute)) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` must not declare `public_shell_route_id` when using `continuation_only`.',
                    $routeId
                ));
            }

            $shape = $this->normalizeShape(
                $rawRoute['shape'] ?? null,
                sprintf('shape for `%s`', $routeId),
            );
            $route['shape'] = $shape;
            $route['path'] = $shape === self::SHAPE_EXACT
                ? $this->normalizeExactPathLiteral(
                    $rawRoute['path'] ?? null,
                    sprintf('exact path for `%s`', $routeId),
                    allowRoot: false,
                )
                : $this->normalizeOneSegmentPrefix(
                    $rawRoute['path'] ?? null,
                    sprintf('one-segment prefix for `%s`', $routeId),
                );
        }

        if ((string) $route['shape'] === self::SHAPE_ONE_SEGMENT && $roles !== [self::ROLE_CONTINUATION]) {
            throw new RuntimeException(sprintf(
                'One-segment project deep-link route `%s` may only carry the `continuation` role.',
                $routeId
            ));
        }

        if (
            $ingressRequirement === self::INGRESS_CONTINUATION_ONLY
            && (
                in_array(self::ROLE_IOS_ASSOCIATION, $roles, true)
                || in_array(self::ROLE_PROMOTION_FALLBACK, $roles, true)
            )
        ) {
            throw new RuntimeException(sprintf(
                'Continuation-only project deep-link route `%s` cannot declare iOS association or promotion fallback roles.',
                $routeId
            ));
        }

        if (in_array(self::ROLE_PROMOTION_FALLBACK, $roles, true)) {
            if (count($roles) > 2) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` cannot combine `promotion_fallback` with canonical query or iOS association roles.',
                    $routeId
                ));
            }

            if (
                $ingressRequirement !== self::INGRESS_PUBLIC_SHELL_REQUIRED
                || (string) $route['shape'] !== self::SHAPE_EXACT
            ) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` may declare `promotion_fallback` only on an exact public-shell route.',
                    $routeId
                ));
            }

            $targetQueryKey = $this->normalizeQueryKey(
                $rawRoute['target_query_key'] ?? null,
                sprintf('promotion fallback target_query_key for `%s`', $routeId),
            );
            if (! in_array($targetQueryKey, $queryKeys, true)) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` promotion fallback target_query_key `%s` must be declared in query_keys.',
                    $routeId,
                    $targetQueryKey
                ));
            }

            $route['target_query_key'] = $targetQueryKey;
        }

        if (in_array(self::ROLE_IOS_ASSOCIATION, $roles, true)) {
            if (
                $ingressRequirement !== self::INGRESS_PUBLIC_SHELL_REQUIRED
                || (string) $route['shape'] !== self::SHAPE_EXACT
            ) {
                throw new RuntimeException(sprintf(
                    'Project deep-link route `%s` may declare `ios_association` only on an exact public-shell route.',
                    $routeId
                ));
            }

            $route['association_emission'] = $this->normalizeAssociationEmission(
                $rawRoute['association_emission'] ?? null,
                sprintf('association_emission for `%s`', $routeId),
            );
        }

        return $route;
    }

    /**
     * @param  mixed  $rawRules
     * @param  array<string, array<string, mixed>>  $routesById
     * @param  array<string, array<string, mixed>>  $inventoryRoutesById
     * @return array<string, mixed>|null
     */
    private function normalizeCanonicalQueryRule(
        mixed $rawRules,
        array $routesById,
        array $inventoryRoutesById,
    ): ?array {
        if ($rawRules === null || $rawRules === []) {
            return null;
        }

        if (! is_array($rawRules)) {
            throw new RuntimeException('canonical_query_rules must be an array when present.');
        }

        $rules = $this->isAssociativeArray($rawRules)
            ? [$rawRules]
            : array_values($rawRules);

        if (count($rules) > 1) {
            throw new RuntimeException('Only one canonical_query_rules entry is allowed.');
        }

        $rawRule = $rules[0] ?? null;
        if (! is_array($rawRule)) {
            throw new RuntimeException('canonical_query_rules entry must be an array.');
        }

        $sourceRouteIds = $this->normalizeRouteIdList(
            $rawRule['source_route_ids'] ?? null,
            'canonical_query_rules.source_route_ids',
            maxCount: 64,
        );
        $targetRouteId = $this->normalizeRouteId(
            $rawRule['target_route_id'] ?? null,
            'canonical_query_rules.target_route_id',
        );
        $absentValueRouteId = $this->normalizeRouteId(
            $rawRule['absent_value_route_id'] ?? null,
            'canonical_query_rules.absent_value_route_id',
        );
        $queryKey = $this->normalizeQueryKey(
            $rawRule['query_key'] ?? null,
            'canonical_query_rules.query_key',
        );
        $externalArgumentKey = $this->normalizeQueryKey(
            $rawRule['external_argument_key'] ?? null,
            'canonical_query_rules.external_argument_key',
        );

        $conflictStrategy = trim((string) ($rawRule['conflict_strategy'] ?? ''));
        if (! in_array($conflictStrategy, [
            self::CANONICAL_CONFLICT_EXTERNAL_WINS,
            self::CANONICAL_CONFLICT_REJECT,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported canonical query conflict strategy `%s`.',
                $conflictStrategy
            ));
        }

        foreach ($sourceRouteIds as $sourceRouteId) {
            $route = $routesById[$sourceRouteId] ?? null;
            if ($route === null) {
                throw new RuntimeException(sprintf(
                    'canonical_query_rules references unknown source route `%s`.',
                    $sourceRouteId
                ));
            }

            if (! in_array(self::ROLE_CANONICAL_QUERY_SOURCE, $route['roles'], true)) {
                throw new RuntimeException(sprintf(
                    'canonical_query_rules source route `%s` must declare the canonical_query_source role.',
                    $sourceRouteId
                ));
            }

            if (! in_array($queryKey, $route['query_keys'], true)) {
                throw new RuntimeException(sprintf(
                    'canonical_query_rules source route `%s` must declare query key `%s`.',
                    $sourceRouteId,
                    $queryKey
                ));
            }
        }

        $targetRoute = $routesById[$targetRouteId] ?? null;
        if ($targetRoute === null) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules references unknown target route `%s`.',
                $targetRouteId
            ));
        }

        if ((string) $targetRoute['shape'] !== self::SHAPE_EXACT) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules target route `%s` must be exact.',
                $targetRouteId
            ));
        }

        if (! in_array(self::ROLE_CANONICAL_QUERY_TARGET, $targetRoute['roles'], true)) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules target route `%s` must declare the canonical_query_target role.',
                $targetRouteId
            ));
        }

        if (! in_array($queryKey, $targetRoute['query_keys'], true)) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules target route `%s` must declare query key `%s`.',
                $targetRouteId,
                $queryKey
            ));
        }

        $absentValueRoute = $routesById[$absentValueRouteId] ?? $inventoryRoutesById[$absentValueRouteId] ?? null;
        if ($absentValueRoute === null) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules references unknown absent_value_route_id `%s`.',
                $absentValueRouteId
            ));
        }

        if ((string) $absentValueRoute['shape'] !== self::SHAPE_EXACT) {
            throw new RuntimeException(sprintf(
                'canonical_query_rules absent_value_route_id `%s` must be exact.',
                $absentValueRouteId
            ));
        }

        return [
            'source_route_ids' => $sourceRouteIds,
            'target_route_id' => $targetRouteId,
            'absent_value_route_id' => $absentValueRouteId,
            'query_key' => $queryKey,
            'external_argument_key' => $externalArgumentKey,
            'conflict_strategy' => $conflictStrategy,
        ];
    }

    /**
     * @param  mixed  $rawRule
     * @param  array<string, array<string, mixed>>  $exactRoutesByPath
     * @return array<string, mixed>|null
     */
    private function normalizeNestedContinuation(
        mixed $rawRule,
        array $exactRoutesByPath,
    ): ?array {
        if ($rawRule === null || $rawRule === []) {
            return null;
        }

        if (! is_array($rawRule)) {
            throw new RuntimeException('nested_continuation must be an array when present.');
        }

        $boundaryPath = $this->normalizeExactPathLiteral(
            $rawRule['boundary_path'] ?? null,
            'nested_continuation.boundary_path',
            allowRoot: false,
        );
        $boundaryMatch = trim((string) ($rawRule['boundary_match'] ?? ''));
        if (! in_array($boundaryMatch, [
            self::NESTED_BOUNDARY_MATCH_EXACT,
            self::NESTED_BOUNDARY_MATCH_EXACT_OR_DESCENDANT,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Unsupported nested_continuation boundary_match `%s`.',
                $boundaryMatch
            ));
        }

        $boundaryRoute = $exactRoutesByPath[$boundaryPath] ?? null;
        if ($boundaryRoute === null) {
            throw new RuntimeException(sprintf(
                'nested_continuation boundary_path `%s` must match an exact compiled route.',
                $boundaryPath
            ));
        }

        $targetQueryKey = $this->normalizeQueryKey(
            $rawRule['target_query_key'] ?? null,
            'nested_continuation.target_query_key',
        );
        if (! in_array($targetQueryKey, $boundaryRoute['query_keys'], true)) {
            throw new RuntimeException(sprintf(
                'nested_continuation target_query_key `%s` must be declared on boundary route `%s`.',
                $targetQueryKey,
                (string) $boundaryRoute['route_id']
            ));
        }

        $maxUnwrapDepth = $this->normalizeIntegerInRange(
            $rawRule['max_unwrap_depth'] ?? null,
            'nested_continuation.max_unwrap_depth',
            min: 1,
            max: 5,
        );

        return [
            'boundary_path' => $boundaryPath,
            'boundary_match' => $boundaryMatch,
            'target_query_key' => $targetQueryKey,
            'max_unwrap_depth' => $maxUnwrapDepth,
            'boundary_route_id' => (string) $boundaryRoute['route_id'],
        ];
    }

    /**
     * @param  mixed  $rawRule
     * @param  array<string, mixed>|null  $canonicalQueryRule
     * @return array<string, mixed>|null
     */
    private function normalizeDeferredCapture(mixed $rawRule, ?array $canonicalQueryRule): ?array
    {
        if ($rawRule === null || $rawRule === []) {
            return null;
        }

        if (! is_array($rawRule)) {
            throw new RuntimeException('deferred_capture must be an array when present.');
        }

        $sourcePrecedence = trim((string) ($rawRule['source_precedence'] ?? ''));
        if ($sourcePrecedence !== self::DEFERRED_SOURCE_PRECEDENCE) {
            throw new RuntimeException(sprintf(
                'Unsupported deferred_capture source_precedence `%s`.',
                $sourcePrecedence
            ));
        }

        $codeKeys = $this->normalizeQueryKeys(
            $rawRule['code_keys'] ?? [],
            'deferred_capture.code_keys',
            maxCount: 4,
        );
        $targetPathKeys = $this->normalizeQueryKeys(
            $rawRule['target_path_keys'] ?? [],
            'deferred_capture.target_path_keys',
            maxCount: 4,
        );
        $nestedPayloadKeys = $this->normalizeQueryKeys(
            $rawRule['nested_payload_keys'] ?? [],
            'deferred_capture.nested_payload_keys',
            maxCount: 4,
        );
        $storeChannelKeys = $this->normalizeQueryKeys(
            $rawRule['store_channel_keys'] ?? [],
            'deferred_capture.store_channel_keys',
            maxCount: 4,
        );

        if ($codeKeys !== []) {
            if ($canonicalQueryRule === null) {
                throw new RuntimeException(
                    'deferred_capture.code_keys requires one compatible canonical_query_rules entry.'
                );
            }

            if (! in_array($canonicalQueryRule['external_argument_key'], $codeKeys, true)) {
                throw new RuntimeException(sprintf(
                    'deferred_capture.code_keys must include canonical_query_rules.external_argument_key `%s`.',
                    (string) $canonicalQueryRule['external_argument_key']
                ));
            }
        }

        return [
            'source_precedence' => $sourcePrecedence,
            'code_keys' => $codeKeys,
            'target_path_keys' => $targetPathKeys,
            'nested_payload_keys' => $nestedPayloadKeys,
            'store_channel_keys' => $storeChannelKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, array<string, mixed>>  $exactRoutesByPath
     * @param  array<int, array<string, mixed>>  $oneSegmentRoutes
     */
    private function assertNoRouteOverlap(
        array $route,
        array $exactRoutesByPath,
        array $oneSegmentRoutes,
    ): void {
        $path = (string) $route['path'];

        if ((string) $route['shape'] === self::SHAPE_EXACT) {
            if (isset($exactRoutesByPath[$path])) {
                throw new RuntimeException(sprintf(
                    'Project deep-link exact path `%s` is declared more than once.',
                    $path
                ));
            }

            foreach ($oneSegmentRoutes as $oneSegmentRoute) {
                if ($this->exactPathMatchesOneSegmentPrefix($path, (string) $oneSegmentRoute['path'])) {
                    throw new RuntimeException(sprintf(
                        'Project deep-link exact path `%s` overlaps one-segment route `%s`.',
                        $path,
                        (string) $oneSegmentRoute['route_id']
                    ));
                }
            }

            return;
        }

        foreach ($oneSegmentRoutes as $oneSegmentRoute) {
            if ((string) $oneSegmentRoute['path'] === $path) {
                throw new RuntimeException(sprintf(
                    'Project deep-link one-segment prefix `%s` is declared more than once.',
                    $path
                ));
            }
        }

        foreach (array_keys($exactRoutesByPath) as $exactPath) {
            if ($this->exactPathMatchesOneSegmentPrefix($exactPath, $path)) {
                throw new RuntimeException(sprintf(
                    'Project deep-link one-segment prefix `%s` overlaps exact path `%s`.',
                    $path,
                    $exactPath
                ));
            }
        }
    }

    private function exactPathMatchesOneSegmentPrefix(string $exactPath, string $oneSegmentPrefix): bool
    {
        if (! str_starts_with($exactPath, $oneSegmentPrefix)) {
            return false;
        }

        $segment = substr($exactPath, strlen($oneSegmentPrefix));

        return $segment !== '' && ! str_contains($segment, '/');
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function associationPathForRoute(array $route): string
    {
        $path = (string) $route['path'];

        return (string) $route['association_emission'] === self::ASSOCIATION_EMISSION_LITERAL_SUFFIX_WILDCARD
            ? $path.'*'
            : $path;
    }

    /**
     * @param  array<string, mixed>  $rawPolicy
     */
    private function assertVersionOne(array $rawPolicy, string $context): void
    {
        $version = $rawPolicy['version'] ?? null;
        if ((int) $version !== 1 || (! is_int($version) && ! is_string($version))) {
            throw new RuntimeException(sprintf(
                '%s must declare `version: 1`.',
                $context
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $rawPolicy
     */
    private function assertNeutralOptOutIsEmpty(array $rawPolicy): void
    {
        $routes = $rawPolicy['routes'] ?? [];
        if ($routes !== []) {
            throw new RuntimeException('explicit_neutral_opt_out policy must not declare routes.');
        }

        foreach (['canonical_query_rules', 'nested_continuation', 'deferred_capture'] as $key) {
            $value = $rawPolicy[$key] ?? null;
            if ($value !== null && $value !== []) {
                throw new RuntimeException(sprintf(
                    'explicit_neutral_opt_out policy must not declare `%s`.',
                    $key
                ));
            }
        }
    }

    private function normalizeRouteId(mixed $value, string $context): string
    {
        $routeId = trim((string) $value);
        if ($routeId === '' || preg_match(self::ROUTE_ID_PATTERN, $routeId) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid %s `%s`.',
                $context,
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }

        return $routeId;
    }

    private function normalizeIngressRequirement(mixed $value, string $context): string
    {
        $ingressRequirement = trim((string) $value);
        if (! in_array($ingressRequirement, [
            self::INGRESS_PUBLIC_SHELL_REQUIRED,
            self::INGRESS_CONTINUATION_ONLY,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Invalid %s `%s`.',
                $context,
                $ingressRequirement
            ));
        }

        return $ingressRequirement;
    }

    private function normalizeShape(mixed $value, string $context): string
    {
        $shape = trim((string) $value);
        if (! in_array($shape, [self::SHAPE_EXACT, self::SHAPE_ONE_SEGMENT], true)) {
            throw new RuntimeException(sprintf(
                'Invalid %s `%s`.',
                $context,
                $shape
            ));
        }

        return $shape;
    }

    /**
     * @param  mixed  $rawRoles
     * @return array<int, string>
     */
    private function normalizeRoles(mixed $rawRoles, string $context): array
    {
        if (! is_array($rawRoles) || $rawRoles === []) {
            throw new RuntimeException(sprintf('%s must declare 1 to 4 roles.', $context));
        }

        $roles = [];
        foreach ($rawRoles as $rawRole) {
            $role = trim((string) $rawRole);
            if (! in_array($role, self::ROLE_SET, true)) {
                throw new RuntimeException(sprintf(
                    'Invalid role `%s` in %s.',
                    $role,
                    $context
                ));
            }

            $roles[] = $role;
        }

        $roles = array_values(array_unique($roles));
        if ($roles === [] || count($roles) > 4) {
            throw new RuntimeException(sprintf('%s must declare 1 to 4 unique roles.', $context));
        }

        return $roles;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeQueryKeys(mixed $rawKeys, string $context, int $maxCount): array
    {
        if ($rawKeys === null) {
            return [];
        }

        if (! is_array($rawKeys)) {
            throw new RuntimeException(sprintf('%s must be an array.', $context));
        }

        $keys = [];
        foreach ($rawKeys as $rawKey) {
            $keys[] = $this->normalizeQueryKey($rawKey, $context);
        }

        $keys = array_values(array_unique($keys));
        if (count($keys) > $maxCount) {
            throw new RuntimeException(sprintf(
                '%s may declare at most %d unique keys.',
                $context,
                $maxCount
            ));
        }

        return $keys;
    }

    private function normalizeQueryKey(mixed $rawKey, string $context): string
    {
        $key = trim((string) $rawKey);
        if ($key === '' || preg_match(self::QUERY_KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid %s `%s`.',
                $context,
                is_scalar($rawKey) ? (string) $rawKey : gettype($rawKey)
            ));
        }

        return $key;
    }

    /**
     * @param  mixed  $rawIds
     * @return array<int, string>
     */
    private function normalizeRouteIdList(mixed $rawIds, string $context, int $maxCount): array
    {
        if (! is_array($rawIds) || $rawIds === []) {
            throw new RuntimeException(sprintf('%s must be a non-empty array.', $context));
        }

        $routeIds = [];
        foreach ($rawIds as $rawId) {
            $routeIds[] = $this->normalizeRouteId($rawId, $context);
        }

        $routeIds = array_values(array_unique($routeIds));
        if (count($routeIds) > $maxCount) {
            throw new RuntimeException(sprintf(
                '%s may declare at most %d route IDs.',
                $context,
                $maxCount
            ));
        }

        return $routeIds;
    }

    private function normalizeAssociationEmission(mixed $value, string $context): string
    {
        $associationEmission = trim((string) $value);
        if (! in_array($associationEmission, [
            self::ASSOCIATION_EMISSION_EXACT,
            self::ASSOCIATION_EMISSION_LITERAL_SUFFIX_WILDCARD,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Invalid %s `%s`.',
                $context,
                $associationEmission
            ));
        }

        return $associationEmission;
    }

    private function normalizeIntegerInRange(mixed $value, string $context, int $min, int $max): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException(sprintf('%s must be an integer.', $context));
        }

        if (! preg_match('/^-?\d+$/', (string) $value)) {
            throw new RuntimeException(sprintf('%s must be an integer.', $context));
        }

        $integer = (int) $value;
        if ($integer < $min || $integer > $max) {
            throw new RuntimeException(sprintf(
                '%s must be between %d and %d.',
                $context,
                $min,
                $max
            ));
        }

        return $integer;
    }

    private function normalizeExactPathLiteral(mixed $value, string $context, bool $allowRoot): string
    {
        $path = $this->normalizeBasePathLiteral($value, $context);
        if ($path === '/' && ! $allowRoot) {
            throw new RuntimeException(sprintf('%s cannot be root.', $context));
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            throw new RuntimeException(sprintf('%s must not end with `/`.', $context));
        }

        return $path;
    }

    private function normalizeOneSegmentPrefix(mixed $value, string $context): string
    {
        $path = $this->normalizeBasePathLiteral($value, $context);
        if ($path === '/' || ! str_ends_with($path, '/')) {
            throw new RuntimeException(sprintf('%s must end with `/` and include one literal prefix segment.', $context));
        }

        $withoutTrailingSlash = substr($path, 0, -1);
        if ($withoutTrailingSlash === '' || $withoutTrailingSlash === false) {
            throw new RuntimeException(sprintf('%s must include one literal prefix segment.', $context));
        }

        return $path;
    }

    private function normalizeBasePathLiteral(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf('%s must be a string path literal.', $context));
        }

        $path = trim($value);
        if ($path === '') {
            throw new RuntimeException(sprintf('%s must not be empty.', $context));
        }

        if (strlen($path) > 256) {
            throw new RuntimeException(sprintf('%s must be at most 256 characters.', $context));
        }

        if (preg_match('/[^\x21-\x7E]/', $path) === 1) {
            throw new RuntimeException(sprintf('%s must be printable ASCII without whitespace.', $context));
        }

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException(sprintf('%s must start with `/`.', $context));
        }

        foreach (['?', '#', '*', '%', '\\'] as $forbidden) {
            if (str_contains($path, $forbidden)) {
                throw new RuntimeException(sprintf(
                    '%s must not contain `%s`.',
                    $context,
                    $forbidden
                ));
            }
        }

        if (str_contains($path, '//')) {
            throw new RuntimeException(sprintf('%s must not contain duplicate slashes.', $context));
        }

        if ($path === '/') {
            return $path;
        }

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(sprintf(
                    '%s contains an invalid literal segment.',
                    $context
                ));
            }
        }

        return $path;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
