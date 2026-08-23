<?php

declare(strict_types=1);

namespace Shared\DeepLinks;

use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Shared\DeepLinks\Application\CompiledProjectRoutePolicy;
use Shared\DeepLinks\Application\ProjectRoutePolicyCompiler;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;
use Shared\DeepLinks\Contracts\PublicShellRouteInventorySourceContract;

class DeepLinksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->ensureHostBinding(AppLinksIdentifierGatewayContract::class);
        $this->ensureHostBinding(AppLinksSettingsSourceContract::class);
        $this->ensureHostBinding(ProjectRoutePolicySourceContract::class);
        $this->ensureHostBinding(PublicShellRouteInventorySourceContract::class);

        $this->app->singleton(CompiledProjectRoutePolicy::class, function ($app): CompiledProjectRoutePolicy {
            return (new ProjectRoutePolicyCompiler(
                $app->make(ProjectRoutePolicySourceContract::class),
                $app->make(PublicShellRouteInventorySourceContract::class),
            ))->compile();
        });
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
