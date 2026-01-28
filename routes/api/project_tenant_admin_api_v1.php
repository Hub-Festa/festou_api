<?php

use App\Http\Api\v1\Controllers\EventStreamController;
use App\Http\Api\v1\Controllers\EventsController;
use App\Http\Api\v1\Controllers\StaticAssetsController;
use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::get('/events', [EventsController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events', [EventsController::class, 'store'])
            ->middleware('abilities:events:create');
        Route::patch('/events/{event_id}', [EventsController::class, 'update'])
            ->middleware('abilities:events:update');
        Route::delete('/events/{event_id}', [EventsController::class, 'destroy'])
            ->middleware('abilities:events:delete');
        Route::get('/events/{event_id}', [EventsController::class, 'show'])
            ->middleware('abilities:events:read');
        Route::get('/events/stream', [EventStreamController::class, 'stream'])
            ->middleware('abilities:events:read');

        Route::prefix('static_assets')
            ->group(function () {
                Route::get('/', [StaticAssetsController::class, 'index'])
                    ->middleware('abilities:assets:read');
                Route::post('/', [StaticAssetsController::class, 'store'])
                    ->middleware('abilities:assets:create');
                Route::get('/{asset_id}', [StaticAssetsController::class, 'show'])
                    ->middleware('abilities:assets:read');
                Route::patch('/{asset_id}', [StaticAssetsController::class, 'update'])
                    ->middleware('abilities:assets:update');
                Route::delete('/{asset_id}', [StaticAssetsController::class, 'destroy'])
                    ->middleware('abilities:assets:delete');
                Route::post('/{asset_id}/restore', [StaticAssetsController::class, 'restore'])
                    ->middleware('abilities:assets:update');
                Route::delete('/{asset_id}/force_delete', [StaticAssetsController::class, 'forceDestroy'])
                    ->middleware('abilities:assets:delete');
            });
    });
