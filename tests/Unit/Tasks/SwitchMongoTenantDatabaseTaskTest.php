<?php

declare(strict_types=1);

namespace Tests\Unit\Tasks;

use App\Actions\MigrateTenantAction;
use App\Models\Landlord\Tenant;
use App\Tasks\SwitchMongoTenantDatabaseTask;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Tests\TestCase;

class SwitchMongoTenantDatabaseTaskTest extends TestCase
{
    private string $originalDefaultConnection;

    private string $originalConfiguredDefaultConnection;

    private mixed $originalTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->originalDefaultConnection = DB::getDefaultConnection();
        $this->originalConfiguredDefaultConnection = (string) config('database.default');
        $this->originalTenantDatabase = config('database.connections.tenant.database');
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->originalConfiguredDefaultConnection]);
        config(['database.connections.tenant.database' => $this->originalTenantDatabase]);
        DB::purge('tenant');
        DB::setDefaultConnection($this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_forget_current_restores_the_captured_original_default_connection(): void
    {
        config(['database.default' => 'mongodb']);
        DB::setDefaultConnection('landlord');

        $tenant = Tenant::make([
            '_id' => 'tenant-switch-test',
            'name' => 'Tenant Switch Test',
            'subdomain' => 'tenant-switch-test',
            'database' => 'tenant_switch_test_db',
        ]);

        $task = new SwitchMongoTenantDatabaseTask;

        $task->makeCurrent($tenant);

        $this->assertSame('tenant', DB::getDefaultConnection());
        $this->assertSame('tenant_switch_test_db', config('database.connections.tenant.database'));

        $task->forgetCurrent();

        $this->assertSame('landlord', DB::getDefaultConnection());
        $this->assertNull(config('database.connections.tenant.database'));
    }

    public function test_migrate_tenant_action_resolves_the_switch_task_from_the_container(): void
    {
        $expectedTask = new class extends SwitchMongoTenantDatabaseTask {};

        $this->app->instance(SwitchMongoTenantDatabaseTask::class, $expectedTask);

        $action = new class extends MigrateTenantAction
        {
            public function exposedSwitchTask(): SwitchTenantTask
            {
                return $this->getSwitchTenantTask();
            }
        };

        $this->assertSame($expectedTask, $action->exposedSwitchTask());
    }
}
