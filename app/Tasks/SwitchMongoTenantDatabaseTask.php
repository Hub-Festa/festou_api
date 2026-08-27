<?php

declare(strict_types=1);

namespace App\Tasks;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchMongoTenantDatabaseTask implements SwitchTenantTask
{
    private ?string $originalDefaultConnection = null;

    public function makeCurrent(IsTenant $tenant): void
    {
        $tenantDatabase = $tenant->getDatabaseName();

        if ($tenantDatabase === null) {
            return;
        }

        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            return;
        }

        if ($this->originalDefaultConnection === null) {
            $this->originalDefaultConnection = DB::getDefaultConnection();
        }

        config([
            "database.connections.$connectionName" => array_merge(
                config("database.connections.$connectionName", []),
                ['database' => $tenantDatabase]
            ),
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
    }

    public function forgetCurrent(): void
    {
        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            return;
        }

        config([
            "database.connections.$connectionName.database" => null,
        ]);

        $originalDefaultConnection = $this->originalDefaultConnection;
        $this->originalDefaultConnection = null;

        DB::purge($connectionName);

        if ($originalDefaultConnection !== null) {
            DB::setDefaultConnection($originalDefaultConnection);
        }
    }
}
