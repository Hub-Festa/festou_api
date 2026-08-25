<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Shared\Favorites\FavoritesServiceProvider::class,
    Shared\Settings\SettingsServiceProvider::class,
    Shared\Media\MediaServiceProvider::class,
    Shared\PushHandler\PushHandlerServiceProvider::class,
    App\Providers\PackageIntegration\FavoritesIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\SettingsIntegrationServiceProvider::class,
    App\Providers\PackageIntegration\DeepLinksIntegrationServiceProvider::class,
    Shared\DeepLinks\DeepLinksServiceProvider::class,
];
