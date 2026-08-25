<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingManifestService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Shared\DeepLinks\Application\DeepLinkAssociationService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function __construct(
        private readonly BrandingManifestService $brandingService,
        private readonly DeepLinkAssociationService $deepLinkAssociationService,
    ) {
    }

    public function getManifest(Request $request): JsonResponse
    {
        $manifestData = $this->brandingService->buildManifest($request->host());

        return response()->json($manifestData)
            ->header('Content-Type', 'application/manifest+json');
    }

    public function getLogoSettingsParameter(String $parameter): String {
        return $this->brandingService->resolveLogoSetting($parameter) ?? '';
    }

    public function getPwaIconParameter(String $parameter): String {
        return $this->brandingService->resolvePwaIcon($parameter) ?? '';
    }

    public function getFavicon(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolveFaviconAsset($request->getHost()),
            $request->getHost(),
        );
    }

    public function getLogoLight(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolveLogoSetting('light_logo_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getLogoDark(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolveLogoSetting('dark_logo_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getMaskableIcon(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolvePwaIcon('icon_maskable512_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getIcon192(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolvePwaIcon('icon192_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getIcon512(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolvePwaIcon('icon512_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getIconLight(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolveLogoSetting('light_icon_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getIconDark(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse(
            $this->brandingService->resolveLogoSetting('dark_icon_uri', $request->getHost()),
            $request->getHost(),
        );
    }

    public function getAssetLinks(): JsonResponse
    {
        return response()->json(
            $this->deepLinkAssociationService->buildAssetLinks()
        )->header('Content-Type', 'application/json');
    }

    public function getAppleAppSiteAssociation(): JsonResponse
    {
        return response()->json(
            $this->deepLinkAssociationService->buildAppleAppSiteAssociation()
        )->header('Content-Type', 'application/json');
    }
}
