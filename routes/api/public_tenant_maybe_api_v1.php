<?php

use App\Http\Api\v1\Controllers\AnonymousIdentityController;
use App\Http\Api\v1\Controllers\AuthControllerAccount;
use App\Http\Api\v1\Controllers\EnvironmentController;
use App\Http\Api\v1\Controllers\MeController;
use App\Http\Api\v1\Controllers\PasswordRegistrationController;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Controllers\TenantTelemetrySettingsController;
use App\Http\Middleware\CheckTenantAccess;
use App\Http\Middleware\EnsureTenantPublicAuthMethod;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

Route::middleware(NeedsTenant::class)->group(function () {
    Route::get('/environment', [EnvironmentController::class, 'showEnvironmentData'])
        ->withoutMiddleware(StartSession::class);

    Route::prefix('anonymous')
        ->group(function () {
            Route::post('/identities', [AnonymousIdentityController::class, 'store']);
        });

    Route::get('/me', [MeController::class, 'tenant'])
        ->middleware(['auth:sanctum', CheckTenantAccess::class]);

    Route::prefix('profile')
        ->middleware(['auth:sanctum', CheckTenantAccess::class])
        ->group(function () {
            Route::patch('/password', [ProfileControllerTenant::class, 'updatePassword'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::patch('/', [ProfileControllerTenant::class, 'updateProfile']);

            Route::patch('/emails', [ProfileControllerTenant::class, 'addEmails']);

            Route::delete('/emails', [ProfileControllerTenant::class, 'removeEmail']);

            Route::patch('/phones', [ProfileControllerTenant::class, 'addPhones']);

            Route::delete('/phones', [ProfileControllerTenant::class, 'removePhone']);
        });

    Route::prefix('auth')
        ->group(function () {
            Route::post('/login', [AuthControllerAccount::class, 'login'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/register/password', PasswordRegistrationController::class)
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/password_token', [ProfileControllerTenant::class, 'generateToken'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::post('/password_reset', [ProfileControllerTenant::class, 'resetPassword'])
                ->middleware(EnsureTenantPublicAuthMethod::class.':password');

            Route::middleware(['auth:sanctum', CheckTenantAccess::class])
                ->group(function () {
                    Route::post('/logout', [AuthControllerAccount::class, 'logout']);

                    Route::get('/token_validate', [AuthControllerAccount::class, 'loginByToken']);
                });
        });

    Route::prefix('settings')
        ->middleware(['auth:sanctum', CheckTenantAccess::class])
        ->group(function () {
            Route::get('/telemetry', [TenantTelemetrySettingsController::class, 'index'])
                ->middleware('abilities:telemetry-settings:update');
            Route::post('/telemetry', [TenantTelemetrySettingsController::class, 'store'])
                ->middleware('abilities:telemetry-settings:update');
            Route::delete('/telemetry/{type}', [TenantTelemetrySettingsController::class, 'destroy'])
                ->middleware('abilities:telemetry-settings:update');
        });
});
