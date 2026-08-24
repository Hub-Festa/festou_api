<?php

declare(strict_types=1);

namespace App\Actions;

use App\Application\Tenants\TenantAppDomainResolverService;
use App\Application\Tenants\TenantDomainResolverService;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class DomainTenantFinder extends TenantFinder
{
    private array $local_environment_alternatives = ['localhost', '127.0.0.1', 'nginx'];

    public function __construct(
        private readonly TenantDomainResolverService $domainResolver,
        private readonly TenantAppDomainResolverService $appDomainResolver,
    )
    {
    }

    public function findForRequest(Request $request): ?IsTenant
    {
        if ($this->isRequestFromSubdomain()) {
            $tenant = $this->findTenantBySubdomain();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        if ($this->isRequestFromLandlordHost()) {
            $tenant = $this->findTenantByAppDomain();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return $this->findTenantByWebDomain();
    }

    protected function findTenantByAppDomain(): ?IsTenant
    {
        $appDomain = $this->resolveAppDomainFromRequest();
        if ($appDomain === null) {
            return null;
        }

        return $this->appDomainResolver->findTenantByIdentifier($appDomain);
    }

    protected function findTenantByWebDomain(): ?IsTenant
    {
        $domain = request()->getHost();

        return $this->domainResolver->findTenantByDomain($domain);
    }

    protected function findTenantBySubdomain(): ?IsTenant
    {
        $subdomain = $this->resolveRequestSubdomain();
        if ($subdomain === null) {
            return null;
        }

        return app(IsTenant::class)::where('subdomain', $subdomain)->first();
    }

    protected function isRequestFromSubdomain(): bool {
        return $this->resolveRequestSubdomain() !== null;
    }

    protected function isRequestFromLandlordHost(): bool
    {
        $host = $this->normalizedHost(request()->getHost());
        $landlordHost = $this->landlordHost();

        if ($host === null || $landlordHost === null) {
            return false;
        }

        return $host === $landlordHost;
    }

    private function landlordHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            $host = trim(str_replace(['https://', 'http://'], '', (string) config('app.url')), '/');
        }

        return $this->normalizedHost($host);
    }

    private function normalizedHost(?string $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $normalized = trim(strtolower($host));

        return $normalized === '' ? null : $normalized;
    }

    private function resolveRequestSubdomain(): ?string
    {
        $host = $this->normalizedHost(request()->getHost());
        $landlordHost = $this->landlordHost();

        if ($host === null || $landlordHost === null || $host === $landlordHost) {
            return null;
        }

        $suffix = '.' . $landlordHost;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));
        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return $subdomain;
    }

    private function resolveAppDomainFromRequest(): ?string
    {
        $request = request();

        $headerDomain = $request->header('X-App-Domain');
        if (is_string($headerDomain) && trim($headerDomain) !== '') {
            return trim($headerDomain);
        }

        $queryDomain = $request->query('app_domain');
        if (is_string($queryDomain) && trim($queryDomain) !== '') {
            return trim($queryDomain);
        }

        $inputDomain = $request->input('app_domain');
        if (is_string($inputDomain) && trim($inputDomain) !== '') {
            return trim($inputDomain);
        }

        return null;
    }
}
