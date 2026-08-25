<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class SettingsMigrationPathGuardrailTest extends TestCase
{
    public function test_tenant_migration_paths_include_shared_settings_package(): void
    {
        $config = require __DIR__.'/../../../config/multitenancy.php';

        $this->assertContains(
            'packages/shared/settings/database/migrations',
            $config['tenant_migration_paths'] ?? [],
        );
    }

    public function test_landlord_migration_paths_include_shared_settings_package(): void
    {
        $config = require __DIR__.'/../../../config/multitenancy.php';

        $this->assertContains(
            'packages/shared/settings/database/migrations_landlord',
            $config['landlord_migration_paths'] ?? [],
        );
    }
}
