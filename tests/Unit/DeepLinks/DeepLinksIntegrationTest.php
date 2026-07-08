<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use App\Integration\DeepLinks\AppLinksIdentifierGatewayAdapter;
use App\Integration\DeepLinks\AppLinksSettingsSourceAdapter;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\Settings\Contracts\SettingsRegistryContract;
use Tests\TestCase;

class DeepLinksIntegrationTest extends TestCase
{
    public function testAppLinksBindingsAndNamespaceAreRegistered(): void
    {
        $this->assertInstanceOf(
            AppLinksIdentifierGatewayAdapter::class,
            $this->app->make(AppLinksIdentifierGatewayContract::class)
        );

        $this->assertInstanceOf(
            AppLinksSettingsSourceAdapter::class,
            $this->app->make(AppLinksSettingsSourceContract::class)
        );

        $registry = $this->app->make(SettingsRegistryContract::class);
        $this->assertNotNull($registry->find('app_links', 'tenant'));
    }
}
