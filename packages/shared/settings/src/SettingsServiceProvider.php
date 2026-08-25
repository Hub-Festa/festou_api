<?php

declare(strict_types=1);

namespace Shared\Settings;

use Shared\Settings\Contracts\SettingsMergePolicyContract;
use Shared\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Shared\Settings\Contracts\SettingsRegistryContract;
use Shared\Settings\Contracts\SettingsSchemaValidatorContract;
use Shared\Settings\Contracts\SettingsStoreContract;
use Shared\Settings\Merge\NamespacePatchMergePolicy;
use Shared\Settings\Registry\InMemorySettingsRegistry;
use Shared\Settings\Stores\MongoSettingsStore;
use Shared\Settings\Validation\ConditionExpressionEvaluator;
use Shared\Settings\Validation\NoopSettingsNamespacePatchGuard;
use Shared\Settings\Validation\SettingsSchemaValidator;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/shared_settings.php', 'shared_settings');

        $this->app->singleton(SettingsRegistryContract::class, InMemorySettingsRegistry::class);
        $this->app->singleton(SettingsMergePolicyContract::class, NamespacePatchMergePolicy::class);
        $this->app->singleton(SettingsSchemaValidatorContract::class, SettingsSchemaValidator::class);
        $this->app->singletonIf(SettingsNamespacePatchGuardContract::class, NoopSettingsNamespacePatchGuard::class);
        $this->app->singleton(SettingsStoreContract::class, MongoSettingsStore::class);
        $this->app->singleton(ConditionExpressionEvaluator::class, ConditionExpressionEvaluator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations_landlord');
    }
}
