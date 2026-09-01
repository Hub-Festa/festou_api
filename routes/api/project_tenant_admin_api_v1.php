<?php

use App\Http\Api\v1\Controllers\AccountOnboardingsController;
use App\Http\Api\v1\Controllers\TenantAdminLegacyCreateGuardController;
use App\Http\Middleware\CheckTenantAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::post('/accounts', [TenantAdminLegacyCreateGuardController::class, 'rejectAccountsCreate'])
            ->middleware('abilities:account-users:create');
        Route::post('/account_profiles', [TenantAdminLegacyCreateGuardController::class, 'rejectAccountProfilesCreate'])
            ->middleware('abilities:account-users:create');
        Route::post('/account_onboardings', [AccountOnboardingsController::class, 'store'])
            ->middleware('abilities:account-users:create');
    });
