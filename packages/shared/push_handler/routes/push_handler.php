<?php

use App\Http\Middleware\CheckTenantAccess;
use Shared\PushHandler\Http\Controllers\Account\PushMessageActionController;
use Shared\PushHandler\Http\Controllers\Account\PushMessageController;
use Shared\PushHandler\Http\Controllers\Account\PushMessageDataController;
use Shared\PushHandler\Http\Controllers\Account\PushMessageSendController;
use Shared\PushHandler\Http\Controllers\Account\PushQuotaCheckController;
use Shared\PushHandler\Http\Controllers\Landlord\TenantPushSettingsAdminController;
use Shared\PushHandler\Http\Controllers\Tenant\PushCredentialController;
use Shared\PushHandler\Http\Controllers\Tenant\PushDeviceController;
use Shared\PushHandler\Http\Controllers\Tenant\PushMessageActionController as TenantPushMessageActionController;
use Shared\PushHandler\Http\Controllers\Tenant\PushMessageController as TenantPushMessageController;
use Shared\PushHandler\Http\Controllers\Tenant\PushMessageDataController as TenantPushMessageDataController;
use Shared\PushHandler\Http\Controllers\Tenant\PushMessageSendController as TenantPushMessageSendController;
use Shared\PushHandler\Http\Controllers\Tenant\TenantPushSettingsController;
use Illuminate\Support\Facades\Route;

$routes = config('shared_push_handler.routes', []);
$accountRoutes = $routes['account'] ?? [];
$tenantRoutes = $routes['tenant'] ?? [];
$landlordRoutes = $routes['landlord'] ?? [];

$accountPrefix = $accountRoutes['prefix'] ?? 'api/v1/accounts/{account_slug}';
$accountMessagesPrefix = $accountRoutes['messages_prefix'] ?? 'push/messages';

$tenantPrefix = $tenantRoutes['prefix'] ?? 'api/v1';
$tenantRegisterPath = $tenantRoutes['register'] ?? 'push/register';
$tenantUnregisterPath = $tenantRoutes['unregister'] ?? 'push/unregister';
$tenantSettingsPrefix = $tenantRoutes['settings_prefix'] ?? 'settings';
$tenantSettingsPushPath = $tenantRoutes['settings_push'] ?? 'push';

$landlordPrefix = $landlordRoutes['prefix'] ?? 'admin/api/v1';
$landlordTenantSettingsPath = $landlordRoutes['tenant_settings_path'] ?? '{tenant_slug}/settings/push';

$mainHost = parse_url((string) config('app.url'), PHP_URL_HOST);
if (! is_string($mainHost) || $mainHost === '') {
    $mainHost = trim((string) config('app.url'));
}
$mainHost = trim($mainHost);
$escapedMainHost = preg_quote($mainHost, '/');
$tenantDomainPattern = $mainHost === ''
    ? '.+'
    : '^(?!' . $escapedMainHost . '$)(?!(?:[^.]+\.){2,}' . $escapedMainHost . '$).+';

Route::domain('{tenant_domain}')
    ->where(['tenant_domain' => $tenantDomainPattern])
    ->group(function () use (
        $accountPrefix,
        $accountMessagesPrefix,
        $tenantPrefix,
        $tenantRegisterPath,
        $tenantUnregisterPath,
        $tenantSettingsPrefix,
        $tenantSettingsPushPath
    ): void {
        Route::prefix($accountPrefix)
    ->middleware(['tenant'])
    ->group(function () use ($accountMessagesPrefix) {
        Route::middleware('auth:sanctum')
            ->group(function () use ($accountMessagesPrefix) {
                Route::get('/push/quota-check', PushQuotaCheckController::class)
                    ->middleware('account', 'abilities:push-messages:send');

                Route::prefix($accountMessagesPrefix)
                    ->group(function () {
                        Route::get('/', [PushMessageController::class, 'index'])
                            ->middleware('account', 'abilities:push-messages:read');
                        Route::post('/', [PushMessageController::class, 'store'])
                            ->middleware('account', 'abilities:push-messages:create');
                        Route::get('/{push_message_id}', [PushMessageController::class, 'show'])
                            ->middleware('account', 'abilities:push-messages:read');
                        Route::patch('/{push_message_id}', [PushMessageController::class, 'update'])
                            ->middleware('account', 'abilities:push-messages:update');
                        Route::delete('/{push_message_id}', [PushMessageController::class, 'destroy'])
                            ->middleware('account', 'abilities:push-messages:delete');

                        Route::get('/{push_message_id}/data', [PushMessageDataController::class, 'show'])
                            ->middleware('account');
                        Route::post('/{push_message_id}/actions', [PushMessageActionController::class, 'store'])
                            ->middleware('account');
                        Route::post('/{push_message_id}/send', PushMessageSendController::class)
                            ->middleware('account', 'abilities:push-messages:send');
                    });
            });
    });

        Route::prefix($tenantPrefix)
    ->middleware(['tenant'])
    ->group(function () use (
        $tenantRegisterPath,
        $tenantUnregisterPath,
        $tenantSettingsPrefix,
        $tenantSettingsPushPath
    ) {
        Route::post('/' . ltrim($tenantRegisterPath, '/'), [PushDeviceController::class, 'register'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class]);
        Route::delete('/' . ltrim($tenantUnregisterPath, '/'), [PushDeviceController::class, 'unregister'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class]);

        Route::prefix('push/messages')
            ->middleware(['auth:sanctum', CheckTenantAccess::class])
            ->group(function () {
                Route::get('/', [TenantPushMessageController::class, 'index'])
                    ->middleware('abilities:tenant-push-messages:read');
                Route::post('/', [TenantPushMessageController::class, 'store'])
                    ->middleware('abilities:tenant-push-messages:create');
                Route::get('/{push_message_id}', [TenantPushMessageController::class, 'show'])
                    ->middleware('abilities:tenant-push-messages:read');
                Route::patch('/{push_message_id}', [TenantPushMessageController::class, 'update'])
                    ->middleware('abilities:tenant-push-messages:update');
                Route::delete('/{push_message_id}', [TenantPushMessageController::class, 'destroy'])
                    ->middleware('abilities:tenant-push-messages:delete');

                Route::get('/{push_message_id}/data', [TenantPushMessageDataController::class, 'show']);
                Route::post('/{push_message_id}/actions', [TenantPushMessageActionController::class, 'store']);
                Route::post('/{push_message_id}/send', TenantPushMessageSendController::class)
                    ->middleware('abilities:tenant-push-messages:send');
            });

        Route::prefix($tenantSettingsPrefix)
            ->middleware(['auth:sanctum', CheckTenantAccess::class])
            ->group(function () use ($tenantSettingsPushPath) {
                Route::get('/' . ltrim($tenantSettingsPushPath, '/'), [TenantPushSettingsController::class, 'show'])
                    ->middleware('abilities:push-settings:update');
                Route::patch('/' . ltrim($tenantSettingsPushPath, '/'), [TenantPushSettingsController::class, 'update'])
                    ->middleware('abilities:push-settings:update');

                Route::prefix('push/credentials')->group(function () {
                    Route::get('/', [PushCredentialController::class, 'index'])
                        ->middleware('abilities:tenant-push-credentials:read');
                    Route::post('/', [PushCredentialController::class, 'store'])
                        ->middleware('abilities:tenant-push-credentials:update');
                    Route::patch('/{credential_id}', [PushCredentialController::class, 'update'])
                        ->middleware('abilities:tenant-push-credentials:update');
                    Route::delete('/{credential_id}', [PushCredentialController::class, 'destroy'])
                        ->middleware('abilities:tenant-push-credentials:update');
                });
            });
        });
    });

Route::domain($mainHost)
    ->group(function () use ($landlordPrefix, $landlordTenantSettingsPath): void {
        Route::prefix($landlordPrefix)
    ->middleware(['landlord'])
    ->group(function () use ($landlordTenantSettingsPath) {
        Route::get('/' . ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'show'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
        Route::patch('/' . ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'update'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
        });
    });
