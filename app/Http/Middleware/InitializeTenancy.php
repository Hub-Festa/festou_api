<?php

namespace App\Http\Middleware;

use App\Actions\DomainTenantFinder;
use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class InitializeTenancy
{
    public function __construct(private readonly DomainTenantFinder $tenantFinder) {}

    public function handle($request, Closure $next)
    {
        $this->resetTenantRuntimeState();

        try {
            $tenant = $this->tenantFinder->findForRequest($request);

            if ($tenant !== null) {
                $tenant->makeCurrent();
            }

            return $next($request);
        } finally {
            $this->resetTenantRuntimeState(force: true);
        }
    }

    private function resetTenantRuntimeState(bool $force = false): void
    {
        if (! $force && ! $this->runtimeIsDirty()) {
            return;
        }

        Tenant::forgetCurrent();

        Context::forget($this->contextKey());
        app()->forgetInstance($this->containerKey());

        config([
            sprintf('database.connections.%s.database', $this->tenantConnectionName()) => null,
        ]);

        DB::purge($this->tenantConnectionName());
        DB::setDefaultConnection($this->baselineDefaultConnection());
    }

    private function runtimeIsDirty(): bool
    {
        return Tenant::current() !== null
            || Context::has($this->contextKey())
            || app()->bound($this->containerKey())
            || $this->tenantDatabaseIsConfigured()
            || DB::getDefaultConnection() !== $this->baselineDefaultConnection();
    }

    private function contextKey(): string
    {
        return (string) config('multitenancy.current_tenant_context_key', 'tenantId');
    }

    private function containerKey(): string
    {
        return (string) config('multitenancy.current_tenant_container_key', 'currentTenant');
    }

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }

    private function baselineDefaultConnection(): string
    {
        return (string) config('database.default', 'mongodb');
    }

    private function tenantDatabaseIsConfigured(): bool
    {
        $database = config(sprintf('database.connections.%s.database', $this->tenantConnectionName()));

        return is_string($database) && trim($database) !== '';
    }
}
