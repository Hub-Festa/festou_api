<?php

declare(strict_types=1);

namespace Shared\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Shared\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Shared\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushSettingsAdminController
{
    public function show(Request $request, string $tenant_slug): JsonResponse
    {
        $tenant = $this->authorizedTenant($request, $tenant_slug);
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();

        $tenant->forgetCurrent();

        return response()->json(['data' => $settings]);
    }

    public function update(TenantPushSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = $this->authorizedTenant($request, $tenant_slug);
        $tenant->makeCurrent();

        $payload = $request->validated();
        if (isset($payload['push_message_routes'])) {
            $payload['push_message_routes'] = $this->normalizeRoutes($payload['push_message_routes']);
        }
        $settings = TenantPushSettings::current();

        if (! $settings) {
            $settings = TenantPushSettings::create($payload);
        } else {
            $settings->fill($payload);
            $settings->save();
        }

        $tenant->forgetCurrent();

        return response()->json(['data' => $settings]);
    }

    private function authorizedTenant(Request $request, string $tenantSlug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $user = $request->user();

        abort_unless(
            $user instanceof LandlordUser
                && in_array((string) $tenant->_id, $user->getAccessToIds(), true),
            403
        );

        return $tenant;
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRoutes(array $routes): array
    {
        return array_map(static function (array $route): array {
            $path = (string) ($route['path'] ?? '');
            preg_match_all('/:([A-Za-z0-9_]+)/', $path, $matches);
            $route['path_params'] = $matches[1] ?? [];
            $route['query_params'] = $route['query_params'] ?? [];
            return $route;
        }, $routes);
    }
}
