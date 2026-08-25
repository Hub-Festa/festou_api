<?php

declare(strict_types=1);

namespace Shared\Favorites;

use Illuminate\Support\ServiceProvider;
use Shared\Favorites\Application\Favorites\FavoritesCommandService;
use Shared\Favorites\Application\Favorites\FavoritesQueryService;
use Shared\Favorites\Contracts\FavoritesRegistryContract;
use Shared\Favorites\Support\InMemoryFavoritesRegistry;

class FavoritesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FavoritesRegistryContract::class, InMemoryFavoritesRegistry::class);
        $this->app->singleton(FavoritesCommandService::class);
        $this->app->singleton(FavoritesQueryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
