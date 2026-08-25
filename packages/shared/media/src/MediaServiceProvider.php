<?php

declare(strict_types=1);

namespace Shared\Media;

use Illuminate\Support\ServiceProvider;
use Shared\Media\Application\ModelMediaService;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModelMediaService::class);
    }
}
