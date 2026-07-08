<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\DeepLinks\AppLinksIdentifierGatewayAdapter;
use App\Integration\DeepLinks\AppLinksSettingsNamespaceRegistrar;
use App\Integration\DeepLinks\AppLinksSettingsSourceAdapter;
use Illuminate\Support\ServiceProvider;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\Settings\Contracts\SettingsRegistryContract;

class DeepLinksIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AppLinksIdentifierGatewayContract::class,
            AppLinksIdentifierGatewayAdapter::class
        );

        $this->app->bind(
            AppLinksSettingsSourceContract::class,
            AppLinksSettingsSourceAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);

        $registrar = $this->app->make(AppLinksSettingsNamespaceRegistrar::class);
        $registrar->register($registry);
    }
}
