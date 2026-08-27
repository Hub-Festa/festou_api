<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Shared\PushHandler\Models\Tenants\TenantPushSettings;

class TenantEnvironmentSnapshotService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly TenantEnvironmentPayloadFactory $payloadFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readResolvedPayload(
        Tenant $tenant,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        $sourceVersion = $this->sourceVersion($tenant);
        $snapshotDocument = $this->currentSnapshotDocument();

        if (! $this->snapshotIsCurrent($tenant, $snapshotDocument, $sourceVersion)) {
            $reason = $this->repairReason($snapshotDocument, $sourceVersion);

            try {
                $snapshot = $this->repair($tenant, $reason, [
                    'trigger' => 'read_path',
                    'request_host' => $requestHost,
                    'source_version' => $sourceVersion,
                ]);

                return $this->hydrateSnapshot(
                    tenant: $tenant,
                    snapshot: $snapshot,
                    requestRoot: $requestRoot,
                    requestHost: $requestHost,
                );
            } catch (\Throwable $exception) {
                if ($this->hasUsableSnapshotDocument($tenant, $snapshotDocument)) {
                    Log::warning('tenant_environment_snapshot_repair_failed_serving_last_valid', [
                        'tenant_id' => (string) $tenant->getKey(),
                        'tenant_slug' => (string) $tenant->slug,
                        'reason' => $reason,
                        'error' => $exception->getMessage(),
                        'snapshot_version' => (string) ($snapshotDocument['snapshot_version'] ?? ''),
                    ]);

                    return $this->hydrateSnapshotPayload(
                        tenant: $tenant,
                        snapshotPayload: $this->snapshotPayload($snapshotDocument),
                        requestRoot: $requestRoot,
                        requestHost: $requestHost,
                    );
                }

                Log::error('tenant_environment_snapshot_repair_failed_falling_back_live', [
                    'tenant_id' => (string) $tenant->getKey(),
                    'tenant_slug' => (string) $tenant->slug,
                    'reason' => $reason,
                    'error' => $exception->getMessage(),
                ]);

                return $this->payloadFactory->buildLiveTenantPayload($tenant, $requestRoot, $requestHost);
            }
        }

        return $this->hydrateSnapshotPayload(
            tenant: $tenant,
            snapshotPayload: $this->snapshotPayload($snapshotDocument),
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function repair(Tenant $tenant, string $reason, array $context = []): TenantEnvironmentSnapshot
    {
        $startedAt = now();
        $started = microtime(true);
        $sourceVersion = $this->sourceVersion($tenant);
        $snapshot = TenantEnvironmentSnapshot::current() ?? new TenantEnvironmentSnapshot([
            '_id' => TenantEnvironmentSnapshot::ROOT_ID,
        ]);

        $snapshot->fill([
            'last_rebuild_reason' => $reason,
            'last_rebuild_context' => $context,
            'last_rebuild_started_at' => $startedAt,
        ]);
        $snapshot->save();

        try {
            $payload = $this->payloadFactory->buildSnapshotSource($tenant);
            $finishedAt = now();
            $snapshotVersion = $this->snapshotVersion($payload);

            $snapshot->fill([
                'schema_version' => self::SCHEMA_VERSION,
                'source_version' => $sourceVersion,
                'snapshot_version' => $snapshotVersion,
                'snapshot' => $payload,
                'built_at' => $finishedAt,
                'last_rebuild_finished_at' => $finishedAt,
                'last_rebuild_failed_at' => null,
                'last_rebuild_error' => null,
            ]);
            $snapshot->save();

            Log::info('tenant_environment_snapshot_rebuilt', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'source_version' => $sourceVersion,
                'snapshot_version' => $snapshotVersion,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $snapshot->fresh() ?? $snapshot;
        } catch (\Throwable $exception) {
            $failedAt = now();
            $snapshot->fill([
                'last_rebuild_finished_at' => $failedAt,
                'last_rebuild_failed_at' => $failedAt,
                'last_rebuild_error' => sprintf(
                    '%s: %s',
                    $exception::class,
                    $exception->getMessage(),
                ),
            ]);
            $snapshot->save();

            Log::error('tenant_environment_snapshot_rebuild_failed', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function snapshotIsCurrent(Tenant $tenant, ?array $snapshotDocument, string $sourceVersion): bool
    {
        if ($snapshotDocument === null) {
            return false;
        }

        if ($this->snapshotSchemaVersion($snapshotDocument) !== self::SCHEMA_VERSION) {
            return false;
        }

        if ((string) ($snapshotDocument['source_version'] ?? '') !== $sourceVersion) {
            return false;
        }

        return $this->hasUsableSnapshotDocument($tenant, $snapshotDocument);
    }

    private function repairReason(?array $snapshotDocument, string $sourceVersion): string
    {
        if ($snapshotDocument === null) {
            return 'missing_snapshot';
        }

        if ($this->snapshotSchemaVersion($snapshotDocument) !== self::SCHEMA_VERSION) {
            return 'version_drift';
        }

        if ((string) ($snapshotDocument['source_version'] ?? '') !== $sourceVersion) {
            return 'source_drift';
        }

        return 'invalid_snapshot';
    }

    private function hasUsableSnapshotDocument(Tenant $tenant, ?array $snapshotDocument): bool
    {
        return $this->hasUsableSnapshotPayload($tenant, $this->snapshotPayload($snapshotDocument));
    }

    private function hasUsableSnapshotPayload(Tenant $tenant, mixed $rawSnapshot): bool
    {
        $snapshot = $this->normalizeMongoValue($rawSnapshot);

        if (! is_array($snapshot)) {
            return false;
        }

        if (($snapshot['type'] ?? null) !== 'tenant') {
            return false;
        }

        if ((string) ($snapshot['tenant_id'] ?? '') !== (string) $tenant->getKey()) {
            return false;
        }

        foreach (['name', 'subdomain', 'canonical_main_domain'] as $requiredStringKey) {
            if (trim((string) ($snapshot[$requiredStringKey] ?? '')) === '') {
                return false;
            }
        }

        if (! array_key_exists('has_explicit_domains', $snapshot) || ! is_bool($snapshot['has_explicit_domains'])) {
            return false;
        }

        foreach (['domains', 'web_domains', 'app_domains', 'branding', 'telemetry', 'firebase', 'push'] as $requiredArrayKey) {
            if (! array_key_exists($requiredArrayKey, $snapshot) || ! is_array($snapshot[$requiredArrayKey])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateSnapshot(
        Tenant $tenant,
        ?TenantEnvironmentSnapshot $snapshot,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        return $this->hydrateSnapshotPayload(
            tenant: $tenant,
            snapshotPayload: $snapshot instanceof TenantEnvironmentSnapshot
                ? $this->rawSnapshotPayload($snapshot)
                : [],
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateSnapshotPayload(
        Tenant $tenant,
        mixed $snapshotPayload,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        return $this->payloadFactory->hydrateTenantPayload(
            tenant: $tenant,
            snapshot: $snapshotPayload,
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentSnapshotDocument(): ?array
    {
        $document = TenantEnvironmentSnapshot::query()->getQuery()->raw(
            fn ($collection) => $collection->findOne(['_id' => TenantEnvironmentSnapshot::ROOT_ID])
        );

        return $this->normalizeSnapshotDocument($document);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeSnapshotDocument(mixed $document): ?array
    {
        $document = $this->normalizeMongoValue($document);

        return is_array($document) ? $document : null;
    }

    private function snapshotSchemaVersion(?array $snapshotDocument): int
    {
        return (int) ($snapshotDocument['schema_version'] ?? 0);
    }

    private function snapshotPayload(?array $snapshotDocument): mixed
    {
        return $snapshotDocument['snapshot'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function snapshotVersion(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'payload' => $this->normalizeForHash($payload),
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: Carbon::now()->toIso8601String(),
        );
    }

    private function sourceVersion(Tenant $tenant): string
    {
        $landlord = Landlord::singleton();
        $pushSettings = TenantPushSettings::current();

        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'tenant' => [
                        'id' => (string) $tenant->getKey(),
                        'name' => (string) $tenant->name,
                        'subdomain' => (string) $tenant->subdomain,
                        'updated_at' => $this->dateMarker($tenant->updated_at ?? null),
                        'branding_data' => $this->normalizeForHash($tenant->branding_data ?? []),
                        'domains' => $this->tenantDomainMarkers($tenant),
                        'app_domains' => $this->normalizeForHash($tenant->app_domains ?? []),
                    ],
                    'landlord' => [
                        'id' => (string) $landlord->getKey(),
                        'updated_at' => $this->dateMarker($landlord->updated_at ?? null),
                        'branding_data' => $this->normalizeForHash($landlord->branding_data ?? []),
                    ],
                    'push_settings' => $pushSettings instanceof TenantPushSettings ? [
                        'id' => (string) $pushSettings->getKey(),
                        'updated_at' => $this->dateMarker($pushSettings->updated_at ?? null),
                        'telemetry' => $this->normalizeForHash($pushSettings->getAttribute('telemetry') ?? []),
                        'firebase' => $this->normalizeForHash($pushSettings->getAttribute('firebase') ?? []),
                        'push' => $this->normalizeForHash($pushSettings->getAttribute('push') ?? []),
                    ] : [],
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: Carbon::now()->toIso8601String(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tenantDomainMarkers(Tenant $tenant): array
    {
        return $tenant->domains()
            ->whereIn('type', [
                Tenant::DOMAIN_TYPE_WEB,
                Tenant::DOMAIN_TYPE_APP_ANDROID,
                Tenant::DOMAIN_TYPE_APP_IOS,
            ])
            ->orderBy('type')
            ->orderBy('path')
            ->get(['_id', 'type', 'path', 'updated_at'])
            ->map(fn ($domain): array => [
                'id' => (string) $domain->getKey(),
                'type' => (string) $domain->type,
                'path' => (string) $domain->path,
                'updated_at' => $this->dateMarker($domain->updated_at ?? null),
            ])
            ->all();
    }

    private function rawSnapshotPayload(TenantEnvironmentSnapshot $snapshot): mixed
    {
        if (method_exists($snapshot, 'getRawOriginal')) {
            return $snapshot->getRawOriginal('snapshot');
        }

        return $snapshot->getAttributes()['snapshot'] ?? null;
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeMongoValue($item);
            }

            return $normalized;
        }

        if (is_object($value) && ! $value instanceof \DateTimeInterface && ! $value instanceof \MongoDB\BSON\UTCDateTime) {
            return $this->normalizeMongoValue((array) $value);
        }

        return $value;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        $value = $this->normalizeMongoValue($value);

        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForHash($item);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value;
    }

    private function dateMarker(mixed $value): ?string
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
