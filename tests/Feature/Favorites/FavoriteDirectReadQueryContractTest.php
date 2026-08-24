<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventOccurrence;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Support\Carbon;
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
        FavoriteEdge::create([
            'owner_user_id' => 'different-owner',
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $firstProfile->_id,
            'favorited_at' => now()->subSeconds(2),
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
        $this->assertStringContainsString("'from' => 'account_profiles'", $source);
        $this->assertStringContainsString("'localField' => '__target_object_id'", $source);
        $this->assertStringContainsString("'foreignField' => '_id'", $source);
        $this->assertStringContainsString("'from' => 'event_occurrences'", $source);
        $this->assertStringContainsString("'foreignField' => 'place_ref.id'", $source);
        $this->assertStringContainsString("'foreignField' => 'place_ref._id'", $source);
        $this->assertStringContainsString("'__sort_block'", $source);
        $this->assertStringContainsString("'__sort_upcoming_at'", $source);
        $this->assertStringContainsString("\$match['place_ref.type'] = 'account_profile';", $source);
        $this->assertStringContainsString("'party_ref_id' => ['\$in' => \$profileIdCandidates]", $source);
        $this->assertStringNotContainsString("->get(['_id', 'target_id', 'favorited_at'])", $source);
        $this->assertStringNotContainsString("'let' => ['profileId' => '\$target_id']", $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
        $this->assertStringNotContainsString($removedVendorNamespace.'\\Favorites', $source);
        $this->assertStringNotContainsString($removedEventsNamespace, $source);
    }

    public function test_event_occurrence_public_agenda_indexes_match_downstream_parity_fields(): void
    {
        $source = $this->readSource(
            'database/migrations/tenants/2026_08_24_000100_add_event_occurrence_public_agenda_indexes.php'
        );
        $removedVendorNamespace = $this->removedVendorNamespace();

        $this->assertStringContainsString('idx_event_occurrences_public_agenda_place_ref_v1', $source);
        $this->assertStringContainsString('idx_event_occurrences_public_agenda_party_ref_v1', $source);
        $this->assertStringContainsString("'place_ref.type' => 1", $source);
        $this->assertStringContainsString("'place_ref.id' => 1", $source);
        $this->assertStringContainsString("'event_parties.party_ref_id' => 1", $source);
        $this->assertStringContainsString("'effective_ends_at' => 1", $source);
        $this->assertStringNotContainsString($removedVendorNamespace, $source);
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

    public function test_mounted_route_preserves_canonical_live_next_recency_and_id_order(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);
        FavoriteEdge::query()->where('owner_user_id', (string) $this->owner->_id)->delete();

        $liveProfile = $this->createProfile('Live Favorite');
        $earlyProfile = $this->createProfile('Early Favorite');
        $lateProfile = $this->createProfile('Late Favorite');
        $plainProfile = $this->createProfile('Plain Favorite');
        $now = Carbon::now();

        $this->createOccurrence($liveProfile, $now->copy()->subHour(), $now->copy()->addHour());
        $this->createOccurrence($earlyProfile, $now->copy()->addHour(), $now->copy()->addHours(2));
        $this->createPartyOccurrence($lateProfile, $now->copy()->addHours(2), $now->copy()->addHours(3));

        $this->createFavorite($liveProfile, $now->copy()->subMinutes(40));
        $this->createFavorite($earlyProfile, $now->copy()->subMinutes(30));
        $this->createFavorite($lateProfile, $now->copy()->subMinutes(20));
        $this->createFavorite($plainProfile, $now->copy()->subMinutes(10));

        $response = $this->getJson($this->favoritesUrl('?page=1&page_size=10'));

        $response->assertOk();
        $this->assertSame([
            (string) $liveProfile->_id,
            (string) $earlyProfile->_id,
            (string) $lateProfile->_id,
            (string) $plainProfile->_id,
        ], array_map(
            static fn (array $item): string => (string) $item['target_id'],
            $response->json('items'),
        ));
        $response->assertJsonPath('has_more', false);
    }

    public function test_mounted_route_preserves_legacy_place_ref_id_compatibility_for_occurrence_rank(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);
        FavoriteEdge::query()->where('owner_user_id', (string) $this->owner->_id)->delete();

        $legacyProfile = $this->createProfile('Legacy Place Ref Favorite');
        $plainProfile = $this->createProfile('Plain Recent Favorite');
        $now = Carbon::now();
        $legacyOccurrence = $this->createLegacyPlaceRefOccurrence(
            $legacyProfile,
            $now->copy()->subHour(),
            $now->copy()->addHour(),
        );

        $this->createFavorite($legacyProfile, $now->copy()->subMinutes(10));
        $this->createFavorite($plainProfile, $now->copy()->subMinute());

        $response = $this->getJson($this->favoritesUrl('?page=1&page_size=10'));

        $response->assertOk();
        $response->assertJsonPath('items.0.target_id', (string) $legacyProfile->_id);
        $response->assertJsonPath(
            'items.0.occurrence_state.live_now_event_occurrence_id',
            (string) $legacyOccurrence->_id,
        );
        $response->assertJsonPath('items.0.navigation.kind', 'event');
        $response->assertJsonPath('items.0.navigation.event_occurrence_id', (string) $legacyOccurrence->_id);
    }

    public function test_mounted_route_applies_deterministic_occurrence_and_favorite_tie_breakers(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);
        FavoriteEdge::query()->where('owner_user_id', (string) $this->owner->_id)->delete();

        $firstNextProfile = $this->createProfile('First Tie Next Favorite');
        $secondNextProfile = $this->createProfile('Second Tie Next Favorite');
        $firstPlainProfile = $this->createProfile('First Tie Plain Favorite');
        $secondPlainProfile = $this->createProfile('Second Tie Plain Favorite');
        $now = Carbon::now();
        $sameNextStart = $now->copy()->addHour();
        $firstOccurrence = $this->createOccurrence(
            $firstNextProfile,
            $sameNextStart,
            $now->copy()->addHours(2),
        );
        $secondOccurrence = $this->createOccurrence(
            $secondNextProfile,
            $sameNextStart,
            $now->copy()->addHours(2),
        );
        $sameFavoriteTime = $now->copy()->subMinute();
        $this->createFavorite($firstNextProfile, $sameFavoriteTime);
        $this->createFavorite($secondNextProfile, $sameFavoriteTime);
        $firstPlainFavorite = $this->createFavorite($firstPlainProfile, $sameFavoriteTime);
        $secondPlainFavorite = $this->createFavorite($secondPlainProfile, $sameFavoriteTime);

        $response = $this->getJson($this->favoritesUrl('?page=1&page_size=10'));

        $response->assertOk();
        $nextOrder = strcmp((string) $firstOccurrence->_id, (string) $secondOccurrence->_id) < 0
            ? [(string) $firstNextProfile->_id, (string) $secondNextProfile->_id]
            : [(string) $secondNextProfile->_id, (string) $firstNextProfile->_id];
        $plainOrder = strcmp((string) $firstPlainFavorite->_id, (string) $secondPlainFavorite->_id) < 0
            ? [(string) $firstPlainProfile->_id, (string) $secondPlainProfile->_id]
            : [(string) $secondPlainProfile->_id, (string) $firstPlainProfile->_id];
        $this->assertSame(
            [...$nextOrder, ...$plainOrder],
            array_map(
                static fn (array $item): string => (string) $item['target_id'],
                $response->json('items'),
            ),
        );
    }

    public function test_mounted_route_filters_inactive_deleted_and_malformed_targets_before_page_sentinel(): void
    {
        config(['favorites.publicly_navigable_profile_types' => ['artist']]);
        FavoriteEdge::query()->where('owner_user_id', (string) $this->owner->_id)->delete();

        $validProfile = $this->createProfile('Valid Favorite');
        $inactiveProfile = $this->createProfile('Inactive Favorite');
        $deletedProfile = $this->createProfile('Deleted Favorite');
        $inactiveProfile->update(['is_active' => false]);
        $deletedProfile->delete();

        $this->createFavorite($inactiveProfile, now()->subMinutes(4));
        $this->createFavorite($deletedProfile, now()->subMinutes(3));
        FavoriteEdge::create([
            'owner_user_id' => (string) $this->owner->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => 'missing-profile',
            'favorited_at' => now()->subMinutes(2),
        ]);
        $this->createFavorite($validProfile, now()->subMinute());

        $page = $this->getJson($this->favoritesUrl('?page=1&page_size=1'));
        $laterPage = $this->getJson($this->favoritesUrl('?page=2&page_size=1'));

        $page->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.target_id', (string) $validProfile->_id)
            ->assertJsonPath('has_more', false);
        $laterPage->assertOk()
            ->assertExactJson([
                'items' => [],
                'has_more' => false,
            ]);
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

    private function createFavorite(AccountProfile $profile, Carbon $favoritedAt): FavoriteEdge
    {
        return FavoriteEdge::create([
            'owner_user_id' => (string) $this->owner->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $profile->_id,
            'favorited_at' => $favoritedAt,
        ]);
    }

    private function createOccurrence(
        AccountProfile $profile,
        Carbon $startsAt,
        Carbon $endsAt,
    ): EventOccurrence {
        return EventOccurrence::create([
            'slug' => Str::slug((string) $profile->display_name).'-'.Str::lower(Str::random(8)),
            'title' => (string) $profile->display_name,
            'is_event_published' => true,
            'starts_at' => $startsAt,
            'effective_ends_at' => $endsAt,
            'ends_at' => $endsAt,
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $profile->_id,
            ],
        ]);
    }

    private function createLegacyPlaceRefOccurrence(
        AccountProfile $profile,
        Carbon $startsAt,
        Carbon $endsAt,
    ): EventOccurrence {
        return EventOccurrence::create([
            'slug' => Str::slug((string) $profile->display_name).'-'.Str::lower(Str::random(8)),
            'title' => (string) $profile->display_name,
            'is_event_published' => true,
            'starts_at' => $startsAt,
            'effective_ends_at' => $endsAt,
            'ends_at' => $endsAt,
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $profile->_id,
            ],
        ]);
    }

    private function createPartyOccurrence(
        AccountProfile $profile,
        Carbon $startsAt,
        Carbon $endsAt,
    ): EventOccurrence {
        return EventOccurrence::create([
            'slug' => Str::slug((string) $profile->display_name).'-'.Str::lower(Str::random(8)),
            'title' => (string) $profile->display_name,
            'is_event_published' => true,
            'starts_at' => $startsAt,
            'effective_ends_at' => $endsAt,
            'ends_at' => $endsAt,
            'place_ref' => [
                'type' => 'other',
                'id' => 'unrelated-profile',
            ],
            'event_parties' => [
                ['party_ref_id' => (string) $profile->_id],
            ],
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
