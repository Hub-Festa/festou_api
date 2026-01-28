<?php

use App\Http\Api\v1\Controllers\AgendaController;
use App\Http\Api\v1\Controllers\EventStreamController;
use App\Http\Api\v1\Controllers\EventsController;
use App\Http\Api\v1\Controllers\MapFiltersController;
use App\Http\Api\v1\Controllers\MapPoiController;
use App\Http\Api\v1\Controllers\MapPoiStreamController;
use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::get('/agenda', [AgendaController::class, 'index']);
        Route::get('/events', [EventsController::class, 'index']);
        Route::get('/events/{event_id}', [EventsController::class, 'show']);
        Route::get('/events/stream', [EventStreamController::class, 'stream']);

        Route::get('/map/pois', [MapPoiController::class, 'index']);
        Route::get('/map/filters', [MapFiltersController::class, 'index']);
        Route::get('/map/pois/stream', [MapPoiStreamController::class, 'stream']);
    });
