<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Shared\DeepLinks\Application\WebToAppPromotionService;

class TenantPublicShellController extends Controller
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
        private readonly FlutterWebShellRenderer $shellRenderer,
        private readonly WebToAppPromotionService $promotionService,
    ) {}

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

        $route = $request->route();
        if ($route !== null) {
            $accountProfileSlug = $route->parameter('account_profile_slug');
            if (is_string($accountProfileSlug) && $accountProfileSlug !== '') {
                return $this->renderShell(
                    $this->metadataService->accountProfileMetadata($accountProfileSlug),
                    $consumeDirectFallbackBypass,
                );
            }

            $eventSlug = $route->parameter('event_slug');
            if (is_string($eventSlug) && $eventSlug !== '') {
                return $this->renderShell(
                    $this->metadataService->eventMetadata($eventSlug),
                    $consumeDirectFallbackBypass,
                );
            }
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

        return in_array(rtrim($path, '/'), ['/baixe-o-app', '/open-app'], true);
    }
}
