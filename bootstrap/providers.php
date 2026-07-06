<?php

return [
    App\Providers\AppServiceProvider::class,
    MongoDB\Laravel\MongoDBServiceProvider::class,
    Shared\Favorites\FavoritesServiceProvider::class,
    Shared\PushHandler\PushHandlerServiceProvider::class,
    App\Providers\PackageIntegration\FavoritesIntegrationServiceProvider::class,
];
