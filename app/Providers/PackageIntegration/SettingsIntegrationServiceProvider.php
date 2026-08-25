<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Settings\CompositeSettingsPatchGuard;
use App\Models\Landlord\Tenant;
use Illuminate\Support\ServiceProvider;
use Shared\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Shared\Settings\Contracts\TenantScopeContextContract;

class SettingsIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SettingsNamespacePatchGuardContract::class,
            CompositeSettingsPatchGuard::class
        );

        $this->app->bind(TenantScopeContextContract::class, static function (): TenantScopeContextContract {
            return new class implements TenantScopeContextContract
            {
                public function runForTenantSlug(string $tenantSlug, callable $callback): mixed
                {
                    $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
                    $tenant->makeCurrent();

                    try {
                        return $callback();
                    } finally {
                        $tenant->forgetCurrent();
                    }
                }
            };
        });
    }
}
