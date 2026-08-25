<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Actions\DomainTenantFinder;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class InitializeTenancyTest extends TestCase
{
    private string $originalDefaultConnection;

    private string $configuredDefaultConnection;

    private mixed $originalTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->configuredDefaultConnection = (string) config('database.default', 'mongodb');
        $this->originalDefaultConnection = DB::getDefaultConnection();
        $this->originalTenantDatabase = config('database.connections.tenant.database');
        $this->resetTenantRuntimeState();
    }

    protected function tearDown(): void
    {
        $this->resetTenantRuntimeState();
        config(['database.connections.tenant.database' => $this->originalTenantDatabase]);
        DB::setDefaultConnection($this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_it_clears_tenant_runtime_state_after_successful_request(): void
    {
        $tenant = $this->tenantFixture();
        $middleware = $this->middlewareResolving($tenant);
        $request = Request::create('https://tenant.example.test/api/v1/environment', 'GET');

        $response = $middleware->handle($request, function () use ($tenant): Response {
            $this->assertSame((string) $tenant->_id, (string) Tenant::current()?->_id);
            $this->assertTrue(Context::has($this->contextKey()));
            $this->assertSame('tenant', DB::getDefaultConnection());
            $this->assertSame($tenant->database, config('database.connections.tenant.database'));

            return new Response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTenantRuntimeReset();
    }

    public function test_it_clears_tenant_runtime_state_when_downstream_throws(): void
    {
        $tenant = $this->tenantFixture();
        $middleware = $this->middlewareResolving($tenant);
        $request = Request::create('https://tenant.example.test/api/v1/environment', 'GET');

        try {
            $middleware->handle($request, function () use ($tenant): never {
                $this->assertSame((string) $tenant->_id, (string) Tenant::current()?->_id);

                throw new RuntimeException('forced downstream failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('forced downstream failure', $exception->getMessage());
        }

        $this->assertTenantRuntimeReset();
    }

    public function test_it_clears_dirty_runtime_state_before_tenant_resolution(): void
    {
        $dirtyTenant = $this->tenantFixture('dirty-tenant', 'dirty_tenant_db');
        $dirtyTenant->makeCurrent();

        $cleanTenant = $this->tenantFixture('clean-tenant', 'clean_tenant_db');
        $middleware = $this->middlewareResolving($cleanTenant, function (): void {
            $this->assertNull(Tenant::current());
            $this->assertFalse(Context::has($this->contextKey()));
            $this->assertSame($this->configuredDefaultConnection, DB::getDefaultConnection());
            $this->assertNull(config('database.connections.tenant.database'));
        });

        $middleware->handle(
            Request::create('https://clean.example.test/api/v1/environment', 'GET'),
            static fn (): Response => new Response('ok')
        );

        $this->assertTenantRuntimeReset();
    }

    private function middlewareResolving(Tenant $tenant, ?callable $beforeReturn = null): InitializeTenancy
    {
        $finder = $this->mock(DomainTenantFinder::class, function (MockInterface $mock) use ($tenant, $beforeReturn): void {
            $mock->shouldReceive('findForRequest')
                ->once()
                ->andReturnUsing(function () use ($tenant, $beforeReturn): Tenant {
                    if ($beforeReturn !== null) {
                        $beforeReturn();
                    }

                    return $tenant;
                });
        });

        return new InitializeTenancy($finder);
    }

    private function tenantFixture(string $id = 'tenant-runtime-test', string $database = 'tenant_runtime_test_db'): Tenant
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            '_id' => $id,
            'name' => 'Tenant Runtime Test',
            'subdomain' => $id,
            'database' => $database,
        ]);

        return $tenant;
    }

    private function assertTenantRuntimeReset(): void
    {
        $this->assertNull(Tenant::current());
        $this->assertFalse(Context::has($this->contextKey()));
        $this->assertFalse(app()->bound($this->containerKey()));
        $this->assertNull(config('database.connections.tenant.database'));
        $this->assertSame($this->configuredDefaultConnection, DB::getDefaultConnection());
    }

    private function resetTenantRuntimeState(): void
    {
        Tenant::forgetCurrent();
        Context::forget($this->contextKey());
        app()->forgetInstance($this->containerKey());
        config(['database.connections.tenant.database' => null]);
        DB::purge('tenant');
        DB::setDefaultConnection($this->configuredDefaultConnection);
    }

    private function contextKey(): string
    {
        return (string) config('multitenancy.current_tenant_context_key', 'tenantId');
    }

    private function containerKey(): string
    {
        return (string) config('multitenancy.current_tenant_container_key', 'currentTenant');
    }
}
