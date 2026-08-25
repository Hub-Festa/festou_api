<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class EnvironmentResolverService
{
    public function __construct(
        private readonly TenantAppDomainResolverService $appDomainResolver,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
        private readonly TenantEnvironmentSnapshotService $tenantEnvironmentSnapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input): array
    {
        $tenant = Tenant::current()
            ?? $this->resolveRequestedTenant($input);

        if ($tenant) {
            $tenant->makeCurrent();

            return $this->tenantEnvironment(
                tenant: $tenant,
                requestRoot: $input['request_root'] ?? null,
                requestHost: $input['request_host'] ?? null
            );
        }

        return $this->landlordEnvironment($input['request_root'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveRequestedTenant(array $input): ?Tenant
    {
        $resolvedTenant = $input['resolved_app_domain_tenant'] ?? null;
        if ($resolvedTenant instanceof Tenant) {
            return $resolvedTenant;
        }

        $appDomain = $input['app_domain'] ?? null;

        return $this->locateTenant(is_string($appDomain) ? $appDomain : null);
    }

    private function locateTenant(?string $appDomain): ?Tenant
    {
        if (! $appDomain) {
            return null;
        }

        return $this->appDomainResolver->findTenantByIdentifier($appDomain);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantEnvironment(Tenant $tenant, ?string $requestRoot, ?string $requestHost): array
    {
        return $this->tenantEnvironmentSnapshots->readResolvedPayload($tenant, $requestRoot, $requestHost);
    }

    /**
     * @return array<string, mixed>
     */
    private function landlordEnvironment(?string $requestRoot): array
    {
        $landlord = Landlord::singleton();
        $branding = $landlord->branding_data ?? [];

        $domainSource = $requestRoot ?? (string) config('app.url');
        $mainDomain = $this->forceHttps($domainSource);

        return [
            'name' => $landlord->name,
            'type' => 'landlord',
            'main_domain' => $mainDomain,
            'landlord_domain' => $mainDomain,
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'public_web_metadata' => $this->resolvePublicWebMetadata($landlord, $branding, $requestRoot),
        ];
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
        Tenant|Landlord $brandable,
        array $branding,
        ?string $requestRoot,
    ): array {
        $metadata = $branding['public_web_metadata'] ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $defaultImage = trim((string) ($metadata['default_image'] ?? ''));
        if ($defaultImage !== '') {
            $baseUrl = $this->forceHttps($requestRoot ?? (string) config('app.url')) ?? (string) config('app.url');
            $defaultImage = (string) (
                $this->brandingPublicWebMediaService->normalizePublicUrl(
                    $baseUrl,
                    $brandable,
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

    private function forceHttps(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        return $normalized === '' ? null : 'https://'.$normalized;
    }
}
