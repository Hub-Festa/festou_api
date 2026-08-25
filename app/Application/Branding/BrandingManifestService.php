<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingManifestService
{
    private const NEUTRAL_FALLBACK_COLOR = '#007D8A';

    public function __construct(
        private readonly TenantDomainResolverService $tenantDomainResolverService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildManifest(string $host): array
    {
        $tenant = Tenant::current();

        $manifest = $tenant !== null
            ? $this->buildTenantManifest($tenant)
            : $this->buildLandlordManifest(Landlord::singleton());

        $manifest['icons'] = [
            [
                'src' => "https://{$host}/icon/icon-192x192.png",
                'sizes' => '192x192',
                'type' => 'image/png',
            ],
            [
                'src' => "https://{$host}/icon/icon-512x512.png",
                'sizes' => '512x512',
                'type' => 'image/png',
            ],
            [
                'src' => "https://{$host}/icon/icon-maskable-512x512.png",
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
        ];

        return $manifest;
    }

    public function resolveLogoSetting(string $parameter, ?string $host = null): ?string
    {
        return $this->resolveBrandingValue('logo_settings', $parameter, $host);
    }

    public function resolvePwaIcon(string $parameter, ?string $host = null): ?string
    {
        return $this->resolveBrandingValue('pwa_icon', $parameter, $host);
    }

    public function resolveFaviconAsset(?string $host = null): ?string
    {
        foreach ($this->brandingsForHost($host) as $branding) {
            foreach ([
                $branding['logo_settings']['favicon_uri'] ?? null,
                $branding['pwa_icon']['icon192_uri'] ?? null,
                $branding['pwa_icon']['icon512_uri'] ?? null,
                $branding['pwa_icon']['source_uri'] ?? null,
            ] as $candidate) {
                if ($this->hasUsableAssetUri($candidate)) {
                    return trim((string) $candidate);
                }
            }
        }

        return null;
    }

    public function resolveStoragePath(?string $uri): ?string
    {
        if (! $uri) {
            return null;
        }

        $urlPath = parse_url($uri, PHP_URL_PATH);

        return $urlPath ? Str::after($urlPath, '/storage/') : null;
    }

    public function assetResponse(?string $path, ?string $host = null): Response|BinaryFileResponse
    {
        $localPath = $this->resolveStoragePath($path);

        if ($localPath !== null && Storage::disk('public')->exists($localPath)) {
            return response()->file(Storage::disk('public')->path($localPath));
        }

        return $this->generatedFallbackResponse($host);
    }

    private function hasUsableAssetUri(mixed $candidate): bool
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return false;
        }

        $path = $this->resolveStoragePath($candidate);

        return $path !== null && Storage::disk('public')->exists($path);
    }

    private function generatedFallbackResponse(?string $host): Response
    {
        $image = Image::create(192, 192)->fill($this->fallbackColor($host));

        return response($image->toPng()->toString(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function fallbackColor(?string $host): string
    {
        foreach ($this->brandingsForHost($host) as $branding) {
            foreach (['primary_seed_color', 'secondary_seed_color'] as $key) {
                $color = $this->normalizeHexColor($branding['theme_data_settings'][$key] ?? null);
                if ($color !== null && $color !== '#FFFFFF') {
                    return $color;
                }
            }
        }

        return self::NEUTRAL_FALLBACK_COLOR;
    }

    private function normalizeHexColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = ltrim(trim($value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $normalized) === 1) {
            $normalized = implode('', array_map(
                static fn (string $character): string => $character.$character,
                str_split($normalized),
            ));
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $normalized) !== 1) {
            return null;
        }

        return '#'.strtoupper($normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function brandingsForHost(?string $host): array
    {
        $brandings = [];
        $tenant = $this->resolveTenantForHost($host);
        if ($tenant instanceof Tenant && is_array($tenant->branding_data)) {
            $brandings[] = $tenant->branding_data;
        }

        $landlordBranding = Landlord::singleton()->branding_data;
        if (is_array($landlordBranding)) {
            $brandings[] = $landlordBranding;
        }

        return $brandings;
    }

    private function resolveBrandingValue(string $section, string $parameter, ?string $host): ?string
    {
        foreach ($this->brandingsForHost($host) as $branding) {
            $value = $branding[$section][$parameter] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveTenantForHost(?string $host): ?Tenant
    {
        $normalizedHost = $this->normalizeHost($host);
        $currentTenant = Tenant::current();

        if ($normalizedHost === null) {
            return $currentTenant;
        }

        $landlordHost = $this->normalizeHost((string) (
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url')
        ));
        if ($landlordHost !== null && $normalizedHost === $landlordHost) {
            return null;
        }

        if ($landlordHost !== null) {
            $subdomainSuffix = '.'.$landlordHost;
            if (str_ends_with($normalizedHost, $subdomainSuffix)) {
                $subdomain = substr($normalizedHost, 0, -strlen($subdomainSuffix));
                if (is_string($subdomain) && $subdomain !== '' && ! str_contains($subdomain, '.')) {
                    $tenant = Tenant::query()->where('subdomain', $subdomain)->first();
                    if ($tenant instanceof Tenant) {
                        return $tenant;
                    }
                }
            }
        }

        return $this->tenantDomainResolverService->findTenantByDomain($normalizedHost) ?? $currentTenant;
    }

    private function normalizeHost(?string $host): ?string
    {
        $normalized = Str::lower(trim((string) $host));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTenantManifest(Tenant $tenant): array
    {
        return $tenant->getManifestData();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLandlordManifest(Landlord $landlord): array
    {
        return $landlord->getManifestData();
    }
}
