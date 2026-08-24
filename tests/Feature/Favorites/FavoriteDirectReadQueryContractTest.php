<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Shared\Favorites\Models\Tenants\FavoriteEdge;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class FavoriteDirectReadQueryContractTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private LandlordUser $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->where('subdomain', 'tenant-favorites')->firstOrFail();
        $this->tenant->makeCurrent();
        $this->owner = LandlordUser::query()->firstOrFail();

        $this->withServerVariables([
            'HTTP_HOST' => "{$this->tenant->subdomain}.{$this->host}",
        ]);
        Sanctum::actingAs($this->owner, AbilityCatalog::all());
    }

    public function test_mounted_route_reads_and_mutates_owner_scoped_favorites_with_pagination(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);

        $firstProfile = $this->createProfile('First Favorite');
        $secondProfile = $this->createProfile('Second Favorite');
        $otherOwnerProfile = $this->createProfile('Other Owner Favorite');

        $this->postJson($this->favoritesUrl(), [
            'target_id' => (string) $firstProfile->_id,
        ])->assertOk()
            ->assertJsonPath('registry_key', 'account_profile')
            ->assertJsonPath('target_type', 'account_profile')
            ->assertJsonPath('target_id', (string) $firstProfile->_id)
            ->assertJsonPath('is_favorite', true);

        $this->postJson($this->favoritesUrl(), [
            'target_id' => (string) $secondProfile->_id,
        ])->assertOk()
            ->assertJsonPath('target_id', (string) $secondProfile->_id)
            ->assertJsonPath('is_favorite', true);

        FavoriteEdge::create([
            'owner_user_id' => 'different-owner',
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $otherOwnerProfile->_id,
            'favorited_at' => now()->subSecond(),
        ]);

        $pageOne = $this->getJson($this->favoritesUrl('?page=1&page_size=1'));
        $pageTwo = $this->getJson($this->favoritesUrl('?page=2&page_size=1'));

        $pageOne->assertOk();
        $pageOne->assertJsonCount(1, 'items');
        $pageOne->assertJsonPath('has_more', true);
        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(1, 'items');
        $pageTwo->assertJsonPath('has_more', false);

        $pageTargetIds = [
            (string) $pageOne->json('items.0.target_id'),
            (string) $pageTwo->json('items.0.target_id'),
        ];
        $this->assertEqualsCanonicalizing([
            (string) $firstProfile->_id,
            (string) $secondProfile->_id,
        ], $pageTargetIds);
        $this->assertNotContains((string) $otherOwnerProfile->_id, $pageTargetIds);

        $this->deleteJson($this->favoritesUrl(), [
            'target_id' => (string) $firstProfile->_id,
        ])->assertOk()
            ->assertJsonPath('target_id', (string) $firstProfile->_id)
            ->assertJsonPath('is_favorite', false);

        $remaining = $this->getJson($this->favoritesUrl());
        $remaining->assertOk();
        $remaining->assertJsonCount(1, 'items');
        $remaining->assertJsonPath('items.0.target_id', (string) $secondProfile->_id);
        $remaining->assertJsonPath('has_more', false);

        $this->deleteJson($this->favoritesUrl(), [
            'target_id' => (string) $secondProfile->_id,
        ])->assertOk()
            ->assertJsonPath('target_id', (string) $secondProfile->_id)
            ->assertJsonPath('is_favorite', false);

        $empty = $this->getJson($this->favoritesUrl());
        $empty->assertOk();
        $empty->assertExactJson([
            'items' => [],
            'has_more' => false,
        ]);
    }

    public function test_mounted_route_isolated_by_tenant_database(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);

        $secondaryTenant = Tenant::create([
            'name' => 'Favorites Secondary',
            'subdomain' => 'favorites-secondary-'.Str::lower(Str::random(8)),
            'domains' => [],
        ]);

        $this->owner->tenantRoles()->create([
            'tenant_id' => (string) $secondaryTenant->_id,
            'name' => 'Favorites Isolation Test Access',
            'permissions' => ['account-users:*'],
        ]);
        $this->owner = $this->owner->fresh();

        $secondaryTenant->makeCurrent();
        $secondaryProfile = $this->createProfile('Secondary Favorite');
        FavoriteEdge::create([
            'owner_user_id' => (string) $this->owner->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $secondaryProfile->_id,
            'favorited_at' => now(),
        ]);

        Sanctum::actingAs($this->owner, AbilityCatalog::all());
        $secondaryList = $this->getJson($this->favoritesUrlFor($secondaryTenant));
        $secondaryList->assertOk();
        $secondaryList->assertJsonPath('items.0.target_id', (string) $secondaryProfile->_id);
        $secondaryList->assertJsonPath('has_more', false);

        $this->tenant->makeCurrent();
        Sanctum::actingAs($this->owner, AbilityCatalog::all());
        $primaryList = $this->getJson($this->favoritesUrlFor($this->tenant));
        $primaryList->assertOk();
        $primaryList->assertExactJson([
            'items' => [],
            'has_more' => false,
        ]);
    }

    public function test_account_profile_favorite_direct_read_uses_canonical_public_agenda_association_fields_and_local_models(): void
    {
        $source = $this->readSource('app/Integration/Favorites/AccountProfileFavoriteDirectReadService.php');
        $removedVendorNamespace = $this->removedVendorNamespace();
        $removedEventsNamespace = $removedVendorNamespace.'\\Events';

        $this->assertStringContainsString('use App\\Models\\Tenants\\AccountProfile;', $source);
        $this->assertStringContainsString('use App\\Models\\Tenants\\EventOccurrence;', $source);
        $this->assertStringContainsString('use Shared\\Favorites\\Models\\Tenants\\FavoriteEdge;', $source);
        $this->assertStringContainsString("where('place_ref.type', 'account_profile')", $source);
        $this->assertStringContainsString("'party_ref_id' => ['\$in' => \$profileIdCandidates]", $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
        $this->assertStringNotContainsString($removedVendorNamespace.'\\Favorites', $source);
        $this->assertStringNotContainsString($removedEventsNamespace, $source);
    }

    public function test_favorites_integration_provider_binds_shared_contract_without_snapshot_rebuilds(): void
    {
        $source = $this->readSource('app/Providers/PackageIntegration/FavoritesIntegrationServiceProvider.php');
        $removedVendorNamespace = $this->removedVendorNamespace();

        $this->assertStringContainsString('Shared\\Favorites\\Contracts\\AccountProfileFavoriteDirectReadContract', $source);
        $this->assertStringContainsString('AccountProfileFavoriteDirectReadService::class', $source);
        $this->assertStringNotContainsString('dispatchSync(', $source);
        $this->assertStringNotContainsString('RebuildFavoriteSnapshotJob', $source);
        $this->assertStringNotContainsString($removedVendorNamespace.'\\Favorites', $source);
    }

    public function test_favorites_registry_config_no_longer_declares_snapshot_runtime_fields(): void
    {
        $source = $this->readSource('config/favorites.php');
        $removedVendorToken = $this->removedVendorToken();

        $this->assertStringContainsString("'default_registry_key' => 'account_profile'", $source);
        $this->assertStringNotContainsString("'snapshot_builder'", $source);
        $this->assertStringNotContainsString("'snapshot_collection'", $source);
        $this->assertStringNotContainsString("'requires_specific_indexes'", $source);
        $this->assertStringNotContainsString($removedVendorToken, $source);
    }

    public function test_public_tenant_route_mounts_shared_favorites_controller(): void
    {
        $source = $this->readSource('routes/api/packages/project_tenant_public_api_v1/favorites.php');
        $removedVendorNamespace = $this->removedVendorNamespace();

        $this->assertStringContainsString('Shared\\Favorites\\Http\\Api\\v1\\Controllers\\FavoritesController', $source);
        $this->assertStringContainsString('NeedsTenant::class', $source);
        $this->assertStringContainsString('CheckTenantAccess::class', $source);
        $this->assertStringNotContainsString($removedVendorNamespace.'\\Favorites', $source);
    }

    public function test_mounted_route_fails_closed_when_host_has_no_tenant(): void
    {
        $this->tenant->forgetCurrent();

        $response = $this->getJson("http://unknown-favorites.{$this->host}/api/v1/favorites");

        $response->assertStatus(400)
            ->assertJson(['message' => 'Tenant not found for this host.']);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }

    private function removedVendorNamespace(): string
    {
        return $this->removedVendorToken();
    }

    private function removedVendorToken(): string
    {
        return 'Bellu'.'ga';
    }

    private function createProfile(string $name): AccountProfile
    {
        $slug = Str::slug($name).'-'.Str::lower(Str::random(8));

        return AccountProfile::create([
            'account_id' => 'account-'.Str::lower(Str::random(8)),
            'profile_type' => 'artist',
            'display_name' => $name,
            'slug' => $slug,
            'avatar_url' => "https://{$this->tenant->subdomain}.{$this->host}/{$slug}.png",
            'cover_url' => "https://{$this->tenant->subdomain}.{$this->host}/{$slug}-cover.png",
            'is_active' => true,
        ]);
    }

    private function favoritesUrl(string $query = ''): string
    {
        return $this->favoritesUrlFor($this->tenant, $query);
    }

    private function favoritesUrlFor(Tenant $tenant, string $query = ''): string
    {
        return "http://{$tenant->subdomain}.{$this->host}/api/v1/favorites{$query}";
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Favorites', 'subdomain' => 'tenant-favorites'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-favorites.test']
        );

        $service->initialize($payload);
    }
}
