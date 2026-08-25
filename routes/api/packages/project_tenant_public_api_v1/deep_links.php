<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Shared\DeepLinks\Http\Api\v1\Controllers\DeferredDeepLinkResolverController;

Route::prefix('deep-links')
    ->group(function (): void {
        Route::post('/deferred/resolve', [DeferredDeepLinkResolverController::class, 'resolve']);
    });
