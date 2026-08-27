<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Support\Str;
use Shared\PushHandler\Models\Tenants\TenantPushSettings;

class TenantEnvironmentPayloadFactory
{
    public function __construct(
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildLiveTenantPayload(
        Tenant $tenant,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        return $this->hydrateTenantPayload(
            tenant: $tenant,
            snapshot: $this->buildSnapshotSource($tenant),
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshotSource(Tenant $tenant): array
    {
        $landlord = Landlord::singleton();
        $pushSettings = TenantPushSettings::current();
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $this->normalizeArray($landlord->branding_data ?? []),
            overrideArray: $this->normalizeArray($tenant->branding_data ?? [])
        );

        return [
            'type' => 'tenant',
            'tenant_id' => (string) $tenant->getKey(),
            'name' => (string) $tenant->name,
            'subdomain' => (string) $tenant->subdomain,
            'canonical_main_domain' => $tenant->getMainDomain(),
            'domains' => $this->relationDomainPaths($tenant),
            'web_domains' => $this->relationDomainPaths($tenant, [Tenant::DOMAIN_TYPE_WEB]),
            'has_explicit_domains' => $this->hasAnyExplicitDomains($tenant),
            'app_domains' => $tenant->resolvedAppDomains(),
            'branding' => $branding,
            'telemetry' => $pushSettings?->getAttribute('telemetry') ?? [],
            'firebase' => $pushSettings?->getAttribute('firebase') ?? [],
            'push' => $pushSettings?->getAttribute('push') ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hydrateTenantPayload(
        Tenant $tenant,
        mixed $snapshot,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        $branding = $this->normalizeArray($this->snapshotValue($snapshot, 'branding', []));
        $subdomain = (string) $this->snapshotValue($snapshot, 'subdomain', $tenant->subdomain);
        $canonicalMainDomain = trim((string) $this->snapshotValue($snapshot, 'canonical_main_domain', ''));
        if ($canonicalMainDomain === '') {
            $canonicalMainDomain = $tenant->getMainDomain();
        }

        return [
            'tenant_id' => (string) $this->snapshotValue($snapshot, 'tenant_id', $tenant->getKey()),
            'name' => (string) $this->snapshotValue($snapshot, 'name', $tenant->name),
            'type' => 'tenant',
            'subdomain' => $subdomain,
            'main_domain' => $this->resolveTenantMainDomain(
                canonicalMainDomain: $canonicalMainDomain,
                webDomains: $this->normalizeStringList($this->snapshotValue($snapshot, 'web_domains', [])),
                hasExplicitDomains: (bool) $this->snapshotValue($snapshot, 'has_explicit_domains', $this->hasAnyExplicitDomains($tenant)),
                tenantSubdomain: $subdomain,
                requestRoot: $requestRoot,
                requestHost: $requestHost,
            ),
            'landlord_domain' => $this->forceHttps((string) config('app.url')),
            'domains' => $this->normalizeStringList($this->snapshotValue($snapshot, 'domains', [])),
            'app_domains' => $this->normalizeStringList($this->snapshotValue($snapshot, 'app_domains', [])),
            'theme_data_settings' => $this->normalizeArray($branding['theme_data_settings'] ?? []),
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'public_web_metadata' => $this->resolvePublicWebMetadata($tenant, $branding, $requestRoot),
            'telemetry' => $this->normalizeArray($this->snapshotValue($snapshot, 'telemetry', [])),
            'firebase' => $this->normalizeArray($this->snapshotValue($snapshot, 'firebase', [])),
            'push' => $this->normalizeArray($this->snapshotValue($snapshot, 'push', [])),
        ];
    }

    /**
     * @param  array<int, string>|null  $types
     * @return array<int, string>
     */
    private function relationDomainPaths(Tenant $tenant, ?array $types = null): array
    {
        $query = $tenant->domains()->orderBy('created_at');
        if ($types !== null) {
            $query->whereIn('type', $types);
        }

        return array_values(array_filter(
            $query->get()
                ->map(static fn (Domains $domain): ?string => $domain->path ? Str::lower(trim($domain->path)) : null)
                ->all(),
            static fn (?string $domain): bool => is_string($domain) && $domain !== ''
        ));
    }

    private function hasAnyExplicitDomains(Tenant $tenant): bool
    {
        if ($tenant->domains()->exists()) {
            return true;
        }

        $embeddedDomains = $tenant->getAttribute('domains');

        return is_array($embeddedDomains) && $embeddedDomains !== [];
    }

    /**
     * @param  array<int, string>  $webDomains
     */
    private function resolveTenantMainDomain(
        string $canonicalMainDomain,
        array $webDomains,
        bool $hasExplicitDomains,
        string $tenantSubdomain,
        ?string $requestRoot,
        ?string $requestHost,
    ): ?string {
        if ($webDomains !== []) {
            return $this->forceHttps($canonicalMainDomain);
        }

        if (! $hasExplicitDomains) {
            $rootHost = $this->resolveRootHost($requestHost, $tenantSubdomain)
                ?? $this->resolveRootHost($requestRoot, $tenantSubdomain)
                ?? $this->resolveRootHost((string) config('app.url'), $tenantSubdomain);

            if ($rootHost) {
                return $this->forceHttps($tenantSubdomain.'.'.$rootHost);
            }
        }

        return $this->forceHttps($canonicalMainDomain);
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveLogoUrl(array $branding, string $key): ?string
    {
        return $branding['logo_settings'][$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveIconUrl(array $branding, string $preferredKey): ?string
    {
        $logoValue = $branding['logo_settings'][$preferredKey] ?? null;

        if ($logoValue) {
            return $logoValue;
        }

        return $branding['pwa_icon']['icon512_uri'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, string>
     */
    private function resolvePublicWebMetadata(
        Tenant $tenant,
        array $branding,
        ?string $requestRoot,
    ): array {
        $metadata = $this->normalizeArray($branding['public_web_metadata'] ?? []);
        $defaultImage = trim((string) ($metadata['default_image'] ?? ''));
        if ($defaultImage !== '') {
            $baseUrl = $this->forceHttps($requestRoot ?? (string) config('app.url')) ?? (string) config('app.url');
            $defaultImage = (string) (
                $this->brandingPublicWebMediaService->normalizePublicUrl(
                    $baseUrl,
                    $tenant,
                    $defaultImage,
                ) ?? ''
            );
        }

        return [
            'default_title' => (string) ($metadata['default_title'] ?? ''),
            'default_description' => (string) ($metadata['default_description'] ?? ''),
            'default_image' => $defaultImage,
        ];
    }

    private function snapshotValue(mixed $snapshot, string $key, mixed $default = null): mixed
    {
        if (is_array($snapshot)) {
            return array_key_exists($key, $snapshot) ? $snapshot[$key] : $default;
        }

        if ($snapshot instanceof \ArrayAccess) {
            return $snapshot->offsetExists($key) ? $snapshot[$key] : $default;
        }

        if (is_object($snapshot) && isset($snapshot->{$key})) {
            return $snapshot->{$key};
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values) && ! $values instanceof \Traversable) {
            return [];
        }

        if ($values instanceof \Traversable) {
            $values = iterator_to_array($values);
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue($item);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $this->normalizeArray($value);
        }

        if ($value instanceof \Traversable) {
            return $this->normalizeArray($value);
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        if (is_object($value) && ! $value instanceof \DateTimeInterface) {
            return $this->normalizeArray($value);
        }

        return $value;
    }

    private function forceHttps(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        return $normalized === '' ? null : 'https://'.$normalized;
    }

    private function resolveRootHost(?string $domain, ?string $tenantSubdomain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        if ($normalized === '') {
            return null;
        }

        if ($tenantSubdomain) {
            $prefix = Str::lower($tenantSubdomain).'.';
            $normalizedLower = Str::lower($normalized);
            if (Str::startsWith($normalizedLower, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized === '' ? null : $normalized;
    }
}
