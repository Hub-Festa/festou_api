<?php

namespace Tests\Api\v1\Tenants\Auth;

use App\Models\Landlord\PersonalAccessToken;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1TenantMeTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    public function test_tenant_me_returns_profile_payload(): void
    {
        $email = fake()->unique()->safeEmail();
        $password = 'Secret!234';

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/register/password",
            data: [
                'name' => 'Tenant Me User',
                'email' => $email,
                'password' => $password,
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-me-test',
            ]
        );

        $login->assertStatus(200);
        $token = $login->json('data.token');
        $this->assertSame(
            $this->landlord->tenant_primary->id,
            trim((string) $this->accessTokenFromPlainText($token)->getAttribute('tenant_id'))
        );

        $response = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenant_id',
            'data' => [
                'user_id',
                'display_name',
                'avatar_url',
                'user_level',
                'privacy_mode',
                'social_score' => [
                    'invites_accepted',
                    'presences_confirmed',
                    'rank_label',
                ],
                'counters' => [
                    'pending_invites',
                    'confirmed_events',
                    'favorites',
                ],
                'role_claims' => [
                    'is_partner',
                    'is_curator',
                    'is_verified',
                ],
            ],
        ]);
        $response->assertJsonPath('data.user_level', 'basic');
        $response->assertJsonPath('data.privacy_mode', 'public');
    }

    public function test_tenant_me_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $token = $this->registeredTenantToken();
        $accessToken = $this->accessTokenFromPlainText($token);
        $accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $accessToken->save();

        $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        )->assertStatus(403);
    }

    public function test_tenant_me_rejects_account_token_with_blank_or_missing_tenant_scope(): void
    {
        foreach ($this->blankTenantScopeMutators() as $label => $mutate) {
            $token = $this->registeredTenantToken();
            $accessToken = $this->accessTokenFromPlainText($token);
            $mutate($accessToken);
            $accessToken->save();

            $this->json(
                method: 'get',
                uri: "{$this->base_api_tenant}me",
                headers: [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ]
            )->assertStatus(403, $label);
        }
    }

    private function registeredTenantToken(): string
    {
        $email = fake()->unique()->safeEmail();
        $password = 'Secret!234';

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/register/password",
            data: [
                'name' => 'Tenant Me Scoped User',
                'email' => $email,
                'password' => $password,
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-me-scope-test',
            ]
        );

        $login->assertStatus(200);

        return (string) $login->json('data.token');
    }

    private function accessTokenFromPlainText(string $plainTextToken): PersonalAccessToken
    {
        $token = PersonalAccessToken::findToken($plainTextToken);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);

        return $token;
    }

    /**
     * @return array<string, callable(PersonalAccessToken): void>
     */
    private function blankTenantScopeMutators(): array
    {
        return [
            'null' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', null),
            'empty_string' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', ''),
            'whitespace_only' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', '   '),
            'missing_attribute' => fn (PersonalAccessToken $token) => $token->offsetUnset('tenant_id'),
        ];
    }
}
