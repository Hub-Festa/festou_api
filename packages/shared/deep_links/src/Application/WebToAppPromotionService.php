<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Application;

use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;

class WebToAppPromotionService
{
    public const string WEB_DIRECT_FALLBACK_BYPASS_COOKIE = 'shared_web_direct_fallback_target';

    private const int MAX_TARGET_PATH_LENGTH = 2048;

    private const int MAX_REDIRECT_UNWRAP_DEPTH = 5;

    public function __construct(
        private readonly AppLinksIdentifierGatewayContract $identifierGateway,
        private readonly CompiledProjectRoutePolicy $compiledRoutePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveRedirectUrl(
        string $origin,
        string $platformTarget,
        string $targetPath,
        ?string $code,
        string $storeChannel,
        array $settings,
        ?string $fallbackMode = null,
    ): string {
        $normalizedOrigin = rtrim($origin, '/');
        $propagatedCode = $this->resolvePropagatedCode(
            targetPath: $targetPath,
            code: $code,
        );
        $openTargetUrl = $this->buildOpenTargetUrl(
            origin: $normalizedOrigin,
            targetPath: $targetPath,
            code: $propagatedCode,
        );

        if ($platformTarget === 'android') {
            return $this->resolveAndroidRedirect(
                openTargetUrl: $openTargetUrl,
                code: $propagatedCode,
                storeChannel: $storeChannel,
                settings: $settings,
                fallbackMode: $fallbackMode,
            );
        }

        if ($platformTarget === 'ios') {
            return $this->resolveIosRedirect(
                openTargetUrl: $openTargetUrl,
                code: $propagatedCode,
                storeChannel: $storeChannel,
                settings: $settings,
            );
        }

        return $openTargetUrl;
    }

    public function detectPlatformTarget(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));
        if ($ua !== '' && str_contains($ua, 'android')) {
            return 'android';
        }
        if ($ua !== '' && (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios'))) {
            return 'ios';
        }

        return 'web';
    }

    public function normalizePlatformTarget(?string $platformTarget): ?string
    {
        $candidate = strtolower(trim((string) $platformTarget));
        if ($candidate === '') {
            return null;
        }

        if ($candidate === 'android' || $candidate === 'ios') {
            return $candidate;
        }

        return null;
    }

    public function normalizeTargetPath(?string $path): string
    {
        return $this->normalizeTargetPathInternal(
            path: $path,
            includeAuthOwnedAppPaths: true,
        ) ?? '/';
    }

    public function normalizeTargetPathOrNull(?string $path): ?string
    {
        return $this->normalizeTargetPathInternal(
            path: $path,
            includeAuthOwnedAppPaths: true,
        );
    }

    public function normalizeCode(?string $code): ?string
    {
        $candidate = trim((string) $code);

        return $candidate === '' ? null : $candidate;
    }

    public function normalizeStoreChannel(?string $storeChannel): string
    {
        $candidate = strtolower(trim((string) $storeChannel));
        if ($candidate === '') {
            return 'web';
        }

        $safe = preg_replace('/[^a-z0-9_\-]/', '', $candidate);
        if (! is_string($safe) || $safe === '') {
            return 'web';
        }

        return $safe;
    }

    public function normalizeFallbackMode(mixed $fallback): ?string
    {
        if (! is_scalar($fallback)) {
            return null;
        }

        $candidate = strtolower(trim((string) $fallback));
        if ($candidate === 'promotion' || $candidate === 'target') {
            return $candidate;
        }

        return null;
    }

    public function coerceFallbackModeForStoreChannel(
        string $storeChannel,
        ?string $fallbackMode,
    ): ?string {
        if ($fallbackMode !== 'target') {
            return $fallbackMode;
        }

        return $storeChannel === 'web_direct'
            ? 'target'
            : 'promotion';
    }

    public function shouldSeedWebDirectFallbackBypassCookie(
        string $platformTarget,
        string $storeChannel,
        ?string $fallbackMode,
    ): bool {
        return $platformTarget === 'android'
            && $storeChannel === 'web_direct'
            && $fallbackMode === 'target';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveAndroidRedirect(
        string $openTargetUrl,
        ?string $code,
        string $storeChannel,
        array $settings,
        ?string $fallbackMode,
    ): string {
        $promotionFallbackUrl = $fallbackMode === 'promotion'
            ? $this->buildPromotionFallbackUrl($openTargetUrl)
            : null;
        $targetFallbackUrl = $fallbackMode === 'target'
            ? $openTargetUrl
            : null;
        $storeUrl = $this->resolveAndroidStoreUrl($settings);
        if ($storeUrl === null) {
            return $promotionFallbackUrl ?? $targetFallbackUrl ?? $openTargetUrl;
        }

        $referrerParams = [
            'store_channel' => $storeChannel,
            'link' => $openTargetUrl,
            'target_path' => $this->targetPathFromOpenTargetUrl($openTargetUrl),
        ];
        if ($code !== null) {
            $referrerParams['code'] = $code;
        }

        $fallbackStoreUrl = $this->appendQuery(
            $storeUrl,
            ['referrer' => http_build_query($referrerParams)],
        );
        $browserFallbackUrl =
            $promotionFallbackUrl ?? $targetFallbackUrl ?? $fallbackStoreUrl;
        $packageName = $this->resolveAndroidPackageName($settings);
        if ($packageName === '') {
            return $browserFallbackUrl;
        }

        return $this->buildAndroidIntentUrl(
            openTargetUrl: $openTargetUrl,
            packageName: $packageName,
            browserFallbackUrl: $browserFallbackUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveIosRedirect(
        string $openTargetUrl,
        ?string $code,
        string $storeChannel,
        array $settings,
    ): string {
        $storeUrl = $this->resolveIosStoreUrl($settings);
        if ($storeUrl === null) {
            return $openTargetUrl;
        }

        $params = [
            'store_channel' => $storeChannel,
            'deep_link' => $openTargetUrl,
        ];
        if ($code !== null) {
            $params['code'] = $code;
        }

        return $this->appendQuery($storeUrl, $params);
    }

    private function buildOpenTargetUrl(
        string $origin,
        string $targetPath,
        ?string $code,
    ): string {
        $canonicalRule = $this->compiledRoutePolicy->canonicalQueryRule();
        $matchedRoute = $this->compiledRoutePolicy->exactRouteForPath(
            $this->pathOnly($targetPath)
        );
        if (
            $canonicalRule !== null
            && $matchedRoute !== null
            && $this->routeParticipatesInCanonicalQuery($matchedRoute, $canonicalRule)
        ) {
            if ($code !== null) {
                $targetRoute = $this->compiledRoutePolicy->routeDefinition(
                    (string) $canonicalRule['target_route_id']
                );
                if ($targetRoute !== null) {
                    return $origin.(string) $targetRoute['path'].'?'.http_build_query([
                        (string) $canonicalRule['query_key'] => $code,
                    ]);
                }
            }

            $absentRoute = $this->compiledRoutePolicy->inventoryRoute(
                (string) $canonicalRule['absent_value_route_id']
            );
            if ($absentRoute !== null) {
                return $origin.(string) $absentRoute['path'];
            }
        }

        if ($targetPath === '/') {
            return $origin.'/';
        }

        return $origin.$targetPath;
    }

    private function resolvePropagatedCode(string $targetPath, ?string $code): ?string
    {
        $canonicalRule = $this->compiledRoutePolicy->canonicalQueryRule();
        if ($canonicalRule === null) {
            return null;
        }

        $matchedRoute = $this->compiledRoutePolicy->exactRouteForPath(
            $this->pathOnly($targetPath)
        );
        if ($matchedRoute === null || ! $this->routeParticipatesInCanonicalQuery($matchedRoute, $canonicalRule)) {
            return null;
        }

        $externalValue = $code !== null ? $this->normalizeCode($code) : null;
        $routeValue = $this->canonicalRouteQueryValue(
            $targetPath,
            (string) $canonicalRule['query_key']
        );

        if ($externalValue === null) {
            return $routeValue;
        }

        if ($routeValue === null) {
            return $externalValue;
        }

        if ((string) $canonicalRule['conflict_strategy'] === 'external_wins') {
            return $externalValue;
        }

        return $externalValue === $routeValue ? $externalValue : null;
    }

    private function normalizeTargetPathInternal(
        ?string $path,
        bool $includeAuthOwnedAppPaths,
        int $unwrapDepth = 0,
    ): ?string {
        $candidate = trim((string) $path);
        if ($candidate === '' || strlen($candidate) > self::MAX_TARGET_PATH_LENGTH || str_starts_with($candidate, '//')) {
            return null;
        }

        if (! str_starts_with($candidate, '/')) {
            $candidate = '/'.$candidate;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $normalizedPath = $this->normalizePath($parts['path'] ?? '/');
        $promotionFallbackPath = $this->compiledRoutePolicy->promotionFallbackPath();
        if (
            $normalizedPath === '/open-app'
            || ($promotionFallbackPath !== null && $normalizedPath === $promotionFallbackPath)
        ) {
            return null;
        }

        $queryParams = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            if (! is_array($queryParams)) {
                $queryParams = [];
            }
        }

        if ($normalizedPath === '/') {
            return '/';
        }

        $nestedContinuation = $this->compiledRoutePolicy->nestedContinuation();
        if (
            $nestedContinuation !== null
            && $this->matchesNestedContinuationBoundary($normalizedPath, $nestedContinuation)
        ) {
            if ($unwrapDepth >= min(
                (int) ($nestedContinuation['max_unwrap_depth'] ?? self::MAX_REDIRECT_UNWRAP_DEPTH),
                self::MAX_REDIRECT_UNWRAP_DEPTH,
            )) {
                return null;
            }

            $nestedRedirect = $queryParams[(string) $nestedContinuation['target_query_key']] ?? null;
            if (! is_string($nestedRedirect) || trim($nestedRedirect) === '') {
                return null;
            }

            return $this->normalizeTargetPathInternal(
                path: $nestedRedirect,
                includeAuthOwnedAppPaths: $includeAuthOwnedAppPaths,
                unwrapDepth: $unwrapDepth + 1,
            );
        }

        $matchedRoute = $this->compiledRoutePolicy->continuationRouteForPath($normalizedPath);
        if ($matchedRoute === null) {
            return null;
        }

        if (
            ! $includeAuthOwnedAppPaths
            && (string) $matchedRoute['ingress_requirement'] === ProjectRoutePolicyCompiler::INGRESS_CONTINUATION_ONLY
        ) {
            return null;
        }

        $canonicalNormalized = $this->normalizeCanonicalQueryPath(
            $matchedRoute,
            $queryParams,
        );
        if ($canonicalNormalized !== null) {
            return $canonicalNormalized;
        }

        $allowedQuery = $this->allowedQueryParametersForRoute($matchedRoute, $queryParams);
        if ($allowedQuery === []) {
            return $normalizedPath;
        }

        return $normalizedPath.'?'.http_build_query($allowedQuery);
    }

    private function normalizePath(string $path): string
    {
        $candidate = trim($path);
        if ($candidate === '') {
            return '/';
        }

        $normalized = str_starts_with($candidate, '/') ? $candidate : '/'.$candidate;
        if (strlen($normalized) > 1 && str_ends_with($normalized, '/')) {
            $normalized = substr($normalized, 0, -1);
        }

        return $normalized;
    }

    private function pathOnly(string $targetPath): string
    {
        $parts = parse_url($targetPath);

        return $this->normalizePath(is_array($parts) ? (string) ($parts['path'] ?? '/') : '/');
    }

    private function targetPathFromOpenTargetUrl(string $openTargetUrl): string
    {
        $parts = parse_url($openTargetUrl);
        if ($parts === false) {
            return '/';
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return $path.$query;
    }

    private function buildPromotionFallbackUrl(string $openTargetUrl): ?string
    {
        $parts = parse_url($openTargetUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower((string) $parts['scheme']).'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        $promotionFallbackPath = $this->compiledRoutePolicy->promotionFallbackPath();
        if ($promotionFallbackPath === null) {
            return null;
        }

        $promotionFallbackRoute = $this->compiledRoutePolicy->exactRouteForPath($promotionFallbackPath);
        if ($promotionFallbackRoute === null) {
            return null;
        }

        return $origin.$promotionFallbackPath.'?'.http_build_query([
            (string) $promotionFallbackRoute['target_query_key'] => $this->targetPathFromOpenTargetUrl($openTargetUrl),
        ]);
    }

    private function buildAndroidIntentUrl(
        string $openTargetUrl,
        string $packageName,
        string $browserFallbackUrl,
    ): string {
        $parts = parse_url($openTargetUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $browserFallbackUrl;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $browserFallbackUrl;
        }

        $normalizedPackageName = preg_replace('/[^A-Za-z0-9_.]/', '', $packageName);
        if (! is_string($normalizedPackageName) || $normalizedPackageName === '') {
            return $browserFallbackUrl;
        }

        $authority = (string) $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return 'intent://'.$authority.$path.$query
            .'#Intent;scheme='.$scheme
            .';package='.$normalizedPackageName
            .';S.browser_fallback_url='.rawurlencode($browserFallbackUrl)
            .';end';
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $canonicalRule
     */
    private function routeParticipatesInCanonicalQuery(array $route, array $canonicalRule): bool
    {
        $routeId = (string) $route['route_id'];

        return in_array($routeId, $canonicalRule['source_route_ids'], true)
            || $routeId === (string) $canonicalRule['target_route_id'];
    }

    private function canonicalRouteQueryValue(string $targetPath, string $queryKey): ?string
    {
        $parts = parse_url($targetPath);
        if ($parts === false || ! isset($parts['query'])) {
            return null;
        }

        parse_str((string) $parts['query'], $params);
        $value = $params[$queryKey] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $queryParams
     */
    private function normalizeCanonicalQueryPath(array $route, array $queryParams): ?string
    {
        $canonicalRule = $this->compiledRoutePolicy->canonicalQueryRule();
        if (
            $canonicalRule === null
            || (string) $route['shape'] !== ProjectRoutePolicyCompiler::SHAPE_EXACT
            || ! $this->routeParticipatesInCanonicalQuery($route, $canonicalRule)
        ) {
            return null;
        }

        $queryKey = (string) $canonicalRule['query_key'];
        $routeValue = $queryParams[$queryKey] ?? null;
        if (! is_string($routeValue) || trim($routeValue) === '') {
            return null;
        }

        $targetRoute = $this->compiledRoutePolicy->routeDefinition(
            (string) $canonicalRule['target_route_id']
        );
        if ($targetRoute === null) {
            return null;
        }

        return (string) $targetRoute['path'].'?'.http_build_query([
            $queryKey => trim($routeValue),
        ]);
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $queryParams
     * @return array<string, string>
     */
    private function allowedQueryParametersForRoute(array $route, array $queryParams): array
    {
        $allowedKeys = $route['query_keys'] ?? [];
        if (! is_array($allowedKeys) || $allowedKeys === []) {
            return [];
        }

        $output = [];
        foreach ($allowedKeys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $value = $queryParams[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $output[$key] = trim($value);
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $nestedContinuation
     */
    private function matchesNestedContinuationBoundary(string $normalizedPath, array $nestedContinuation): bool
    {
        $boundaryPath = (string) $nestedContinuation['boundary_path'];
        if ($normalizedPath === $boundaryPath) {
            return true;
        }

        if ((string) $nestedContinuation['boundary_match'] === 'exact') {
            return false;
        }

        return str_starts_with($normalizedPath, $boundaryPath.'/');
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        $merged = array_merge($existing, $params);
        $query = http_build_query($merged);

        $rebuilt = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== '') {
            $rebuilt .= '?'.$query;
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveAndroidStoreUrl(array $settings): ?string
    {
        if (! $this->isPlatformEnabled($settings, 'android')) {
            return null;
        }

        $url = trim((string) ($settings['android']['store_url'] ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveIosStoreUrl(array $settings): ?string
    {
        if (! $this->isPlatformEnabled($settings, 'ios')) {
            return null;
        }

        $url = trim((string) ($settings['ios']['store_url'] ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveAndroidPackageName(array $settings): string
    {
        if (! $this->isPlatformEnabled($settings, 'android')) {
            return '';
        }

        return trim((string) ($this->identifierGateway->identifierForPlatform('android') ?? ''));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isPlatformEnabled(array $settings, string $platform): bool
    {
        return (bool) ($settings[$platform]['enabled'] ?? false);
    }
}
