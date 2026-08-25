<?php

declare(strict_types=1);

namespace Shared\PushHandler;

use Shared\PushHandler\Contracts\PushPlanPolicyContract;
use Shared\PushHandler\Contracts\PushAudienceEligibilityContract;
use Shared\PushHandler\Contracts\FcmClientContract;
use Shared\PushHandler\Services\PushPlanPolicyAllowAll;
use Shared\PushHandler\Services\PushAudienceEligibilityAllowAll;
use Shared\PushHandler\Services\FcmHttpV1Client;
use Illuminate\Support\ServiceProvider;

class PushHandlerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/push_handler.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/shared_push_handler.php', 'shared_push_handler');

        if (! $this->app->bound(PushPlanPolicyContract::class)) {
            $this->app->bind(PushPlanPolicyContract::class, PushPlanPolicyAllowAll::class);
        }

        if (! $this->app->bound(PushAudienceEligibilityContract::class)) {
            $this->app->bind(PushAudienceEligibilityContract::class, PushAudienceEligibilityAllowAll::class);
        }

        if (! $this->app->bound(FcmClientContract::class)) {
            $this->app->bind(FcmClientContract::class, FcmHttpV1Client::class);
        }
    }
}
