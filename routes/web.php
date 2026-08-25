<?php

use App\Http\Api\v1\Controllers\BrandingController;
use App\Http\Api\v1\Controllers\EnvironmentController;
use App\Application\PublicWeb\ProjectPublicShellRouteRegistrar;
use App\Http\Controllers\TenantPublicShellController;
use Illuminate\Support\Facades\Route;
use Shared\DeepLinks\Http\Web\Controllers\OpenAppRedirectController;

Route::middleware(['auth'])->group(function () {

});

Route::middleware('tenant-maybe')->group(function () {
    Route::get('/open-app', [OpenAppRedirectController::class, 'redirect']);
    Route::get('/.well-known/assetlinks.json', [BrandingController::class, 'getAssetLinks']);
    Route::get('/.well-known/apple-app-site-association', [BrandingController::class, 'getAppleAppSiteAssociation']);
    Route::get('/', [TenantPublicShellController::class, 'fallback']);
    Route::get('/privacy-policy', [TenantPublicShellController::class, 'fallback']);
    app(ProjectPublicShellRouteRegistrar::class)->register();
    Route::get('/manifest.json', [BrandingController::class, 'getManifest']);
    Route::get('/favicon.ico', [BrandingController::class, 'getFavicon']);
    Route::get('/icon/icon-maskable-512x512.png', [BrandingController::class, 'getMaskableIcon']);
    Route::get('/icon/icon-192x192.png', [BrandingController::class, 'getIcon192']);
    Route::get('/icon/icon-512x512.png', [BrandingController::class, 'getIcon512']);
    Route::get('/icon-light.png', [BrandingController::class, 'getIconLight']);
    Route::get('/icon-dark.png', [BrandingController::class, 'getIconDark']);
    Route::get('/logo-light.png', [BrandingController::class, 'getLogoLight']);
    Route::get('/logo-dark.png', [BrandingController::class, 'getLogoDark']);
});
