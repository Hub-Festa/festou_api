<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Landlord;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class LandlordBrandingController
{

    use HasLogoFiles;

    public function __construct(
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $landlord = Landlord::singleton();
        $newData = $request->validated();

        $uploadedLogoUrls = $this->processLogoUploads($request);

        if ($request->hasFile('public_web_metadata.default_image')) {
            $newData['public_web_metadata']['default_image'] = $this->brandingPublicWebMediaService->storeDefaultImage(
                $request->getSchemeAndHttpHost(),
                $landlord,
                $request->file('public_web_metadata.default_image')
            );
        }

        $brandingArray = $newData;
        $brandingArray['logo_settings'] = $uploadedLogoUrls;

        if ($request->hasFile("logo_settings.pwa_icon")) {
            $brandingArray['pwa_icon'] = $this->generatePwaIconVariants(
                sourceFile: $request->file("logo_settings.pwa_icon"),
            );
        }

        $landlord->branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray:  $landlord->branding_data,
            overrideArray: $brandingArray
        );
        if ($request->hasFile('public_web_metadata.default_image')) {
            $landlord->branding_data['public_web_metadata']['default_image'] = (string) $newData['public_web_metadata']['default_image'];
        }
        $landlord->branding_data = $this->brandingPublicWebMediaService->materializeBrandingData(
            $request->getSchemeAndHttpHost(),
            $landlord,
            $landlord->branding_data
        );
        $landlord->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $landlord->branding_data,
        ]);
    }
}
