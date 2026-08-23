<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\ProjectPublicShellRouteRegistry;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Shared\DeepLinks\Application\CompiledProjectRoutePolicy;
use Shared\DeepLinks\Application\WebToAppPromotionService;

class TenantPublicShellController extends Controller
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
        private readonly ProjectPublicShellRouteRegistry $projectRouteRegistry,
        private readonly FlutterWebShellRenderer $shellRenderer,
        private readonly WebToAppPromotionService $promotionService,
        private readonly CompiledProjectRoutePolicy $compiledRoutePolicy,
    ) {}

    public function projectRoute(
        Request $request,
        ?string $projectPublicShellSegment = null,
    ): Response|RedirectResponse
    {
        $route = $request->route();
        $routeId = $route === null
            ? ''
            : trim((string) ($route->defaults['project_public_shell_route_id'] ?? ''));
        if ($routeId === '') {
            abort(404);
        }

        $definition = $this->projectRouteRegistry->routeDefinition($routeId);
        $requestedUri = $this->projectRouteRegistry->requestedPathForDefinition(
            $request,
            $definition,
            $projectPublicShellSegment,
        );
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $requestedUri,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $requestedUri,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->projectRouteRegistry->metadataForRoute(
                $request,
                $routeId,
                $projectPublicShellSegment,
            ),
            $consumeDirectFallbackBypass,
        );
    }

    public function fallback(
        Request $request,
        ?string $fallbackPath = null,
    ): Response|RedirectResponse
    {
        $requestedUri = $this->requestTargetPath($request, $fallbackPath);
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $requestedUri,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $requestedUri,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->metadataService->defaultMetadata(
                $requestedUri
            ),
            $consumeDirectFallbackBypass,
        );
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function renderShell(
        array $metadata,
        bool $forgetDirectFallbackBypass = false,
    ): Response
    {
        $response = response(
            $this->shellRenderer->render($metadata),
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            ]
        );

        if ($forgetDirectFallbackBypass) {
            $response->cookie(
                cookie()->forget(
                    WebToAppPromotionService::WEB_DIRECT_FALLBACK_BYPASS_COOKIE
                )
            );
        }

        return $response;
    }

    private function requestTargetPath(
        Request $request,
        ?string $fallbackPath,
    ): string
    {
        $requestedUri = trim((string) $request->getRequestUri());
        if ($requestedUri !== '') {
            return $requestedUri;
        }

        $trimmed = trim((string) $fallbackPath);
        if ($trimmed === '') {
            return '/';
        }

        return '/'.ltrim($trimmed, '/');
    }

    private function redirectToInstalledAppIfAndroid(
        Request $request,
        string $targetPath,
        bool $suppressDirectHandoff = false,
    ): ?RedirectResponse {
        if ($suppressDirectHandoff) {
            return null;
        }

        if (
            $this->promotionService->detectPlatformTarget($request->userAgent())
            !== 'android'
        ) {
            return null;
        }

        if ($this->shouldSkipDirectAndroidHandoffForKnownInAppBrowser($request)) {
            return null;
        }

        if ($this->isPromotionBoundaryPath($targetPath)) {
            return null;
        }

        return redirect()->to('/open-app?'.http_build_query([
            'path' => $this->promotionService->normalizeTargetPath($targetPath),
            'store_channel' => 'web_direct',
            'platform_target' => 'android',
            'fallback' => 'target',
        ]));
    }

    private function shouldConsumeDirectFallbackBypass(
        Request $request,
        string $targetPath,
    ): bool {
        $cookieTargetPath = trim((string) $request->cookie(
            WebToAppPromotionService::WEB_DIRECT_FALLBACK_BYPASS_COOKIE
        ));

        if ($cookieTargetPath === '') {
            return false;
        }

        return $cookieTargetPath === $this->promotionService
            ->normalizeTargetPath($targetPath);
    }

    private function isPromotionBoundaryPath(string $targetPath): bool
    {
        $parts = parse_url($targetPath);
        $path = is_array($parts)
            ? (string) ($parts['path'] ?? '/')
            : $targetPath;

        $normalizedPath = rtrim($path, '/');
        if ($normalizedPath === '/open-app') {
            return true;
        }

        $promotionFallbackPath = $this->compiledRoutePolicy->promotionFallbackPath();
        if ($promotionFallbackPath === null) {
            return false;
        }

        return $normalizedPath === rtrim($promotionFallbackPath, '/');
    }

    private function shouldSkipDirectAndroidHandoffForKnownInAppBrowser(Request $request): bool
    {
        $userAgent = strtolower(trim((string) $request->userAgent()));
        if ($userAgent === '') {
            return false;
        }

        foreach (['instagram', 'fban', 'fbav', 'fb_iab', 'messenger'] as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }

        return false;
    }
}
