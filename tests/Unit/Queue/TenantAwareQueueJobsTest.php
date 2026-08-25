<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Shared\PushHandler\Jobs\SendPushMessageJob;
use Tests\TestCase;
use Tests\Traits\EnsuresPlatformInitialization;

class TenantAwareQueueJobsTest extends TestCase
{
    use EnsuresPlatformInitialization;

    public function test_queue_runtime_remains_tenant_aware_by_default_for_local_queue_jobs(): void
    {
        $this->assertTrue((bool) config('multitenancy.queues_are_tenant_aware_by_default'));
        $this->assertFalse(in_array(
            SendPushMessageJob::class,
            config('multitenancy.not_tenant_aware_jobs', []),
            true,
        ));
    }

    public function test_representative_tenant_aware_dispatches_store_jobs_in_shared_queue_storage_only(): void
    {
        $this->ensureSystemInitialized();
        $this->useMongoQueueRuntimeForTest();
        $this->makeTenantCurrent();

        SendPushMessageJob::dispatch('queue-guardrail-message', 'tenant', null);

        $this->assertSame(1, $this->queueJobCount('mongodb'));
        $this->assertSame(0, $this->queueJobCount('tenant'));
    }

    private function useMongoQueueRuntimeForTest(): void
    {
        config([
            'queue.default' => 'mongodb',
            'queue.connections.mongodb.connection' => 'mongodb',
            'queue.connections.mongodb.collection' => 'jobs',
            'queue.connections.mongodb.queue' => 'default',
            'queue.failed.driver' => 'null',
        ]);

        app('db')
            ->connection('mongodb')
            ->table('jobs')
            ->delete();

        app('db')
            ->connection('tenant')
            ->table('jobs')
            ->delete();
    }

    private function queueJobCount(string $connection): int
    {
        return (int) app('db')
            ->connection($connection)
            ->table('jobs')
            ->count();
    }
}
