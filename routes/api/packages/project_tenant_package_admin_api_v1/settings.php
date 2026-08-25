<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;
use Shared\Settings\Http\Api\v1\Controllers\Tenant\SettingsKernelController;

$tenantSettingsPrefix = 'settings';

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () use ($tenantSettingsPrefix): void {
        Route::prefix($tenantSettingsPrefix)
            ->group(function (): void {
                Route::get('/schema', [SettingsKernelController::class, 'schema']);
                Route::get('/values', [SettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [SettingsKernelController::class, 'patch']);
            });
    });
