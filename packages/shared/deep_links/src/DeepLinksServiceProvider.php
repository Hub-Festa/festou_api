<?php

declare(strict_types=1);

namespace Shared\DeepLinks;

use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;

class DeepLinksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->ensureHostBinding(AppLinksIdentifierGatewayContract::class);
        $this->ensureHostBinding(AppLinksSettingsSourceContract::class);
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("Shared deep links host binding missing for [{$abstract}]");
        });
    }
}
