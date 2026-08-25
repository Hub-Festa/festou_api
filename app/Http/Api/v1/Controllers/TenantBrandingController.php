<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Tenants\TenantBrandingManagementService;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Tenant;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class TenantBrandingController
{
    use HasLogoFiles;

    public function __construct(
        private readonly TenantBrandingManagementService $brandingService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {
    }

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $validated = $request->validated();
        $uploadedLogos = $this->processLogoUploads($request);

        if ($request->hasFile('public_web_metadata.default_image')) {
            $validated['public_web_metadata']['default_image'] = $this->brandingPublicWebMediaService->storeDefaultImage(
                $request->getSchemeAndHttpHost(),
                $tenant,
                $request->file('public_web_metadata.default_image')
            );
        }

        $pwaVariants = [];
        if ($request->hasFile('logo_settings.pwa_icon')) {
            $pwaVariants = $this->generatePwaIconVariants(
                sourceFile: $request->file('logo_settings.pwa_icon')
            );
        }

        $brandingData = $this->brandingService->update(
            $tenant,
            $validated,
            $uploadedLogos,
            $pwaVariants
        );
        if ($request->hasFile('public_web_metadata.default_image')) {
            $brandingData['public_web_metadata']['default_image'] = (string) $validated['public_web_metadata']['default_image'];
        }
        $brandingData = $this->brandingPublicWebMediaService->materializeBrandingData(
            $request->getSchemeAndHttpHost(),
            $tenant,
            $brandingData
        );

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $brandingData,
        ]);
    }

}
