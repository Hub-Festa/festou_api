<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\CheckTenantAccess;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CheckTenantAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget('tenantId');
        auth()->forgetGuards();

        parent::tearDown();
    }

    public function test_tenant_scoped_account_user_with_transient_token_is_allowed_without_persisted_token_scope(): void
    {
        Context::add('tenantId', 'tenant-transient-token-test');

        $user = new AccountUser;
        $user->withAccessToken(new TransientToken);

        $this->assertNotInstanceOf(PersonalAccessToken::class, $user->currentAccessToken());

        auth('sanctum')->setUser($user);
        auth()->shouldUse('sanctum');

        $response = (new CheckTenantAccess)->handle(
            Request::create('/api/v1/me', 'GET'),
            static fn (): Response => new Response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }
}
