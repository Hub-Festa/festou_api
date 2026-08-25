<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Application;

class DeferredDeepLinkResolverService
{
    private const int MAX_RAW_SOURCE_LENGTH = 6144;
    private const int MAX_SCALAR_VALUE_LENGTH = 256;
    private const int MAX_TARGET_PATH_LENGTH = 2048;
    private const int MAX_STORE_CHANNEL_LENGTH = 64;
    private const string STORE_CHANNEL_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    public function __construct(
        private readonly CompiledProjectRoutePolicy $compiledRoutePolicy,
        private readonly WebToAppPromotionService $promotionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        ?string $deferredPayload,
        ?string $installReferrer,
        ?string $fallbackStoreChannel = null,
    ): array {
        $policy = $this->compiledRoutePolicy->deferredCapture();
        $storeChannelFallback = $this->normalizeOptionalStoreChannel($fallbackStoreChannel);
        if ($policy === null) {
            return $this->notCaptured($storeChannelFallback, 'deferred_capture_unconfigured');
        }

        $deferredPayloadState = $this->sourceState($deferredPayload);
        if ($deferredPayloadState['present']) {
            if ($deferredPayloadState['invalid']) {
                return $this->notCaptured($storeChannelFallback, 'source_invalid');
            }

            return $this->resolveSource(
                $deferredPayloadState['value'],
                $policy,
                $storeChannelFallback,
            );
        }

        $installReferrerState = $this->sourceState($installReferrer);
        if ($installReferrerState['present']) {
            if ($installReferrerState['invalid']) {
                return $this->notCaptured($storeChannelFallback, 'source_invalid');
            }

            return $this->resolveSource(
                $installReferrerState['value'],
                $policy,
                $storeChannelFallback,
            );
        }

        return $this->notCaptured($storeChannelFallback, 'source_unavailable');
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function resolveSource(
        string $source,
        array $policy,
        ?string $fallbackStoreChannel,
        bool $allowNestedPayload = true,
    ): array
    {
        $parsed = $this->parseQueryParameters(
            raw: $source,
            declaredKeys: $this->declaredDeferredKeys($policy),
        );
        if (! $parsed['valid']) {
            return $this->notCaptured($fallbackStoreChannel, 'source_invalid');
        }

        $params = $parsed['params'];
        $storeChannel = $this->resolveStoreChannel($params, $policy, $fallbackStoreChannel);

        $directCode = $this->resolveUniqueDecodedValue(
            $params,
            $policy['code_keys'] ?? [],
            maxLength: self::MAX_SCALAR_VALUE_LENGTH,
            allowResidualPercent: false,
        );
        if ($directCode['status'] === 'conflicting' || $directCode['status'] === 'source_invalid') {
            return $this->notCaptured($storeChannel, 'source_invalid');
        }
        if ($directCode['status'] === 'valid') {
            return $this->capturedWithCode((string) $directCode['value'], $storeChannel);
        }

        $directTarget = $this->resolveUniqueTargetPath($params, $policy['target_path_keys'] ?? []);
        if ($directTarget['status'] === 'conflicting' || $directTarget['status'] === 'source_invalid') {
            return $this->notCaptured($storeChannel, 'source_invalid');
        }
        if ($directTarget['status'] === 'valid') {
            return $this->capturedWithTargetPath((string) $directTarget['value'], $storeChannel);
        }

        if (! $allowNestedPayload) {
            return $this->notCaptured($storeChannel, 'capture_missing');
        }

        $nestedPayload = $this->resolveUniqueDecodedValue(
            $params,
            $policy['nested_payload_keys'] ?? [],
            maxLength: self::MAX_RAW_SOURCE_LENGTH,
            allowResidualPercent: true,
        );
        if ($nestedPayload['status'] === 'conflicting' || $nestedPayload['status'] === 'source_invalid') {
            return $this->notCaptured($storeChannel, 'source_invalid');
        }
        if ($nestedPayload['status'] === 'valid') {
            return $this->resolveNestedPayload(
                (string) $nestedPayload['value'],
                $policy,
                $storeChannel,
            );
        }

        return $this->notCaptured($storeChannel, 'capture_missing');
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function resolveNestedPayload(string $rawNestedPayload, array $policy, ?string $storeChannel): array
    {
        $decodedPayload = $this->decodeFormComponent($rawNestedPayload);
        if ($decodedPayload === null || strlen($decodedPayload) > self::MAX_RAW_SOURCE_LENGTH) {
            return $this->notCaptured($storeChannel, 'source_invalid');
        }

        $nestedState = $this->sourceState($decodedPayload);
        if ($nestedState['invalid']) {
            return $this->notCaptured($storeChannel, 'source_invalid');
        }
        if (! $nestedState['present'] || ! is_string($nestedState['value'])) {
            return $this->notCaptured($storeChannel, 'capture_missing');
        }

        return $this->resolveSource(
            $nestedState['value'],
            $policy,
            $storeChannel,
            allowNestedPayload: false,
        );
    }

    /**
     * @param  array<string, list<string>>  $params
     * @param  array<string, mixed>  $policy
     */
    private function resolveStoreChannel(
        array $params,
        array $policy,
        ?string $fallbackStoreChannel,
    ): ?string {
        $captured = $this->resolveUniqueStoreChannelValue($params, $policy['store_channel_keys'] ?? []);
        if ($captured['status'] === 'valid') {
            return (string) $captured['value'];
        }

        return $fallbackStoreChannel;
    }

    /**
     * @param  array<string, list<string>>  $params
     * @param  array<int, mixed>  $keys
     * @return array{status: string, value: ?string}
     */
    private function resolveUniqueDecodedValue(
        array $params,
        array $keys,
        int $maxLength,
        bool $allowResidualPercent,
    ): array
    {
        $sawCandidate = false;
        $values = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            foreach ($params[$key] ?? [] as $value) {
                $sawCandidate = true;
                $normalized = trim($value);
                if ($normalized === '') {
                    continue;
                }

                if (strlen($normalized) > $maxLength) {
                    return ['status' => 'source_invalid', 'value' => null];
                }

                if (! $allowResidualPercent && str_contains($normalized, '%')) {
                    return ['status' => 'source_invalid', 'value' => null];
                }

                $values[] = $normalized;
            }
        }

        if (! $sawCandidate) {
            return ['status' => 'absent', 'value' => null];
        }

        $values = array_values(array_unique($values));
        if ($values === []) {
            return ['status' => 'collapse_to_absence', 'value' => null];
        }

        if (count($values) > 1) {
            return ['status' => 'conflicting', 'value' => null];
        }

        return ['status' => 'valid', 'value' => $values[0]];
    }

    /**
     * @param  array<string, list<string>>  $params
     * @param  array<int, mixed>  $keys
     * @return array{status: string, value: ?string}
     */
    private function resolveUniqueTargetPath(array $params, array $keys): array
    {
        $resolved = $this->resolveUniqueDecodedValue(
            $params,
            $keys,
            maxLength: self::MAX_RAW_SOURCE_LENGTH,
            allowResidualPercent: false,
        );
        if ($resolved['status'] !== 'valid') {
            return $resolved;
        }

        if (strlen((string) $resolved['value']) > self::MAX_TARGET_PATH_LENGTH) {
            return ['status' => 'source_invalid', 'value' => null];
        }

        $normalizedTargetPath = $this->promotionService->normalizeTargetPathOrNull((string) $resolved['value']);
        if ($normalizedTargetPath === null) {
            return ['status' => 'collapse_to_absence', 'value' => null];
        }

        return ['status' => 'valid', 'value' => $normalizedTargetPath];
    }

    /**
     * @return array{present: bool, invalid: bool, value: ?string}
     */
    private function sourceState(?string $source): array
    {
        $normalized = trim((string) $source);
        if ($normalized === '') {
            return [
                'present' => false,
                'invalid' => false,
                'value' => null,
            ];
        }

        if (strlen($normalized) > self::MAX_RAW_SOURCE_LENGTH) {
            return [
                'present' => true,
                'invalid' => true,
                'value' => null,
            ];
        }

        return [
            'present' => true,
            'invalid' => false,
            'value' => $normalized,
        ];
    }

    /**
     * @param  array<int, string>  $declaredKeys
     * @return array{valid: bool, params: array<string, list<string>>}
     */
    private function parseQueryParameters(string $raw, array $declaredKeys): array
    {
        $queryStringState = $this->extractQueryString($raw);
        if (! $queryStringState['valid']) {
            return ['valid' => false, 'params' => []];
        }

        $declaredKeySet = [];
        foreach ($declaredKeys as $declaredKey) {
            if ($declaredKey !== '') {
                $declaredKeySet[$declaredKey] = true;
            }
        }

        $query = $queryStringState['query'];
        if ($query === '') {
            return ['valid' => true, 'params' => []];
        }

        $output = [];
        foreach (explode('&', $query) as $component) {
            if ($component === '') {
                continue;
            }

            $parts = explode('=', $component, 2);
            $rawKey = $parts[0] ?? '';
            $rawValue = $parts[1] ?? '';
            $decodedKey = $this->decodeFormComponent($rawKey);
            if ($decodedKey === null) {
                return ['valid' => false, 'params' => []];
            }

            if (str_contains($decodedKey, '[') || str_contains($decodedKey, ']')) {
                $baseKey = preg_replace('/\\[.*$/', '', $decodedKey);
                if (is_string($baseKey) && isset($declaredKeySet[$baseKey])) {
                    return ['valid' => false, 'params' => []];
                }

                continue;
            }

            if (! isset($declaredKeySet[$decodedKey])) {
                continue;
            }

            $decodedValue = $this->decodeFormComponent($rawValue);
            if ($decodedValue === null) {
                return ['valid' => false, 'params' => []];
            }

            $output[$decodedKey] ??= [];
            $output[$decodedKey][] = $decodedValue;
        }

        return ['valid' => true, 'params' => $output];
    }

    private function normalizeOptionalStoreChannel(?string $storeChannel): ?string
    {
        $normalized = trim((string) $storeChannel);
        if ($normalized === '') {
            return null;
        }

        return $this->normalizeStoreChannelCandidate($normalized);
    }

    /**
     * @param  array<string, list<string>>  $params
     * @param  array<int, mixed>  $keys
     * @return array{status: string, value: ?string}
     */
    private function resolveUniqueStoreChannelValue(array $params, array $keys): array
    {
        $sawCandidate = false;
        $values = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            foreach ($params[$key] ?? [] as $value) {
                $sawCandidate = true;
                $normalized = trim($value);
                if ($normalized === '') {
                    continue;
                }

                $token = $this->normalizeStoreChannelCandidate($normalized);
                if ($token !== null) {
                    $values[] = $token;
                }
            }
        }

        if (! $sawCandidate) {
            return ['status' => 'absent', 'value' => null];
        }

        $values = array_values(array_unique($values));
        if (count($values) !== 1) {
            return ['status' => 'collapse_to_absence', 'value' => null];
        }

        return ['status' => 'valid', 'value' => $values[0]];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    private function declaredDeferredKeys(array $policy): array
    {
        $keys = array_merge(
            $policy['code_keys'] ?? [],
            $policy['target_path_keys'] ?? [],
            $policy['nested_payload_keys'] ?? [],
            $policy['store_channel_keys'] ?? [],
        );

        return array_values(array_filter($keys, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * @return array{valid: bool, query: string}
     */
    private function extractQueryString(string $raw): array
    {
        $normalized = trim($raw);
        if ($normalized === '') {
            return ['valid' => true, 'query' => ''];
        }

        if (str_starts_with($normalized, '?')) {
            return ['valid' => true, 'query' => substr($normalized, 1)];
        }

        if (
            str_starts_with($normalized, '/')
            || str_contains($normalized, '?')
            || str_contains($normalized, '://')
        ) {
            $parts = parse_url($normalized);
            if ($parts === false) {
                return ['valid' => false, 'query' => ''];
            }

            $query = $parts['query'] ?? null;

            return [
                'valid' => true,
                'query' => is_string($query) ? $query : '',
            ];
        }

        return ['valid' => true, 'query' => $normalized];
    }

    private function decodeFormComponent(string $raw): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            return null;
        }

        return rawurldecode($raw);
    }

    private function normalizeStoreChannelCandidate(string $storeChannel): ?string
    {
        if (strlen($storeChannel) > self::MAX_STORE_CHANNEL_LENGTH) {
            return null;
        }

        $normalized = strtolower($storeChannel);
        if (preg_match(self::STORE_CHANNEL_PATTERN, $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedWithCode(string $code, ?string $storeChannel): array
    {
        $canonicalRule = $this->compiledRoutePolicy->canonicalQueryRule();
        $targetRoute = $canonicalRule !== null
            ? $this->compiledRoutePolicy->routeDefinition((string) $canonicalRule['target_route_id'])
            : null;
        if ($canonicalRule === null || $targetRoute === null) {
            return $this->notCaptured($storeChannel, 'deferred_capture_unconfigured');
        }

        $queryKey = $canonicalRule['query_key'] ?? 'code';

        return [
            'status' => 'captured',
            'code' => $code,
            'target_path' => (string) $targetRoute['path'].'?'.http_build_query([(string) $queryKey => $code]),
            'store_channel' => $storeChannel,
            'failure_reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedWithTargetPath(string $targetPath, ?string $storeChannel): array
    {
        return [
            'status' => 'captured',
            'code' => null,
            'target_path' => $targetPath,
            'store_channel' => $storeChannel,
            'failure_reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notCaptured(?string $storeChannel, string $failureReason): array
    {
        return [
            'status' => 'not_captured',
            'code' => null,
            'target_path' => '/',
            'store_channel' => $storeChannel,
            'failure_reason' => $failureReason,
        ];
    }
}
