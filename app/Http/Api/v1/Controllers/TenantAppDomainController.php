<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantAppDomainManagementService;
use App\Http\Api\v1\Requests\TenantAppDomainRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class TenantAppDomainController extends Controller
{
    public function __construct(
        private readonly TenantAppDomainManagementService $appDomainService
    ) {
    }

    public function index(): JsonResponse
    {
        $tenant = Tenant::resolve();

        return response()->json([
            'app_domains' => $this->appDomainService->list($tenant),
        ]);
    }

    public function store(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domains = $this->appDomainService->upsert(
            tenant: $tenant,
            platform: $request->platform(),
            identifier: $request->identifier(),
        );

        return response()->json([
            'message' => 'App domain identifier saved successfully.',
            'app_domains' => $domains,
        ]);
    }

    public function destroy(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domains = $this->appDomainService->remove(
            tenant: $tenant,
            platform: $request->platform(),
        );

        return response()->json([
            'message' => 'App domain identifier removed successfully.',
            'app_domains' => $domains,
        ]);
    }
}
