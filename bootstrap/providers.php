<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Belluga\Settings\SettingsServiceProvider::class,
    Belluga\PushHandler\PushHandlerServiceProvider::class,
    Belluga\DiscoveryFilters\DiscoveryFiltersServiceProvider::class,
    Belluga\Media\MediaServiceProvider::class,
    Belluga\DeepLinks\DeepLinksServiceProvider::class,
    Belluga\Email\EmailServiceProvider::class,
    App\Providers\PackageIntegration\MediaIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\EnvironmentSnapshotIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\SettingsIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\DiscoveryFiltersIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\PushIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\EmailIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\DeepLinksIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\ContactChannelsIntegrationServiceProvider::class,
];
