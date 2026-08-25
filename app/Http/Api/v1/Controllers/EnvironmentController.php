<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Environment\EnvironmentResolverService;
use App\Http\Api\v1\Requests\EnvironmentRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvironmentResolverService $environmentService
    ) {
    }

    public function showEnvironmentData(EnvironmentRequest $request): JsonResponse
    {
        $resolved = $this->environmentService->resolve([
            ...$request->validated(),
            'resolved_app_domain_tenant' => $request->resolvedAppDomainTenant(),
            'request_root' => $request->root(),
            'request_host' => $request->getHost(),
        ]);

        $domains = $resolved['domains'] ?? [];
        if (is_array($domains)) {
            $domains = array_map(static function ($domain): string {
                if (is_string($domain)) {
                    return $domain;
                }

                return (string) ($domain['path'] ?? $domain->path ?? '');
            }, $domains);
            $domains = array_values(array_filter($domains, static fn (string $domain): bool => $domain !== ''));
        }

        $payload = [
            'type' => $resolved['type'] ?? null,
            'tenant_id' => $resolved['tenant_id'] ?? null,
            'name' => $resolved['name'] ?? null,
            'subdomain' => $resolved['subdomain'] ?? null,
            'main_domain' => $resolved['main_domain'] ?? null,
            'landlord_domain' => $resolved['landlord_domain'] ?? null,
            'domains' => $domains,
            'app_domains' => $resolved['app_domains'] ?? [],
            'theme_data_settings' => $resolved['theme_data_settings'] ?? [],
            'public_web_metadata' => $resolved['public_web_metadata'] ?? [],
            'telemetry' => $resolved['telemetry'] ?? [],
            'firebase' => $resolved['firebase'] ?? [],
            'push' => $resolved['push'] ?? [],
        ];

        if (array_key_exists('settings', $resolved)) {
            $payload['settings'] = $resolved['settings'];
        }

        return response()->json($payload);
    }
}
