<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;
use Shared\Favorites\Http\Api\v1\Controllers\FavoritesController;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/favorites', [FavoritesController::class, 'index']);
        Route::post('/favorites', [FavoritesController::class, 'store']);
        Route::delete('/favorites', [FavoritesController::class, 'destroy']);
    });
