<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use Tests\TestCase;

class FavoriteDirectReadQueryContractTest extends TestCase
{
    public function test_account_profile_favorite_direct_read_uses_canonical_public_agenda_association_fields_and_local_models(): void
    {
        $source = $this->readSource('app/Integration/Favorites/AccountProfileFavoriteDirectReadService.php');
        $legacyVendorNamespace = $this->legacyVendorNamespace();
        $legacyEventsNamespace = $legacyVendorNamespace.'\\Events';

        $this->assertStringContainsString('use App\\Models\\Tenants\\AccountProfile;', $source);
        $this->assertStringContainsString('use App\\Models\\Tenants\\EventOccurrence;', $source);
        $this->assertStringContainsString('use Shared\\Favorites\\Models\\Tenants\\FavoriteEdge;', $source);
        $this->assertStringContainsString("where('place_ref.type', 'account_profile')", $source);
        $this->assertStringContainsString("'party_ref_id' => ['\$in' => \$profileIdCandidates]", $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
        $this->assertStringNotContainsString($legacyVendorNamespace.'\\Favorites', $source);
        $this->assertStringNotContainsString($legacyEventsNamespace, $source);
    }

    public function test_favorites_integration_provider_binds_shared_contract_without_snapshot_rebuilds(): void
    {
        $source = $this->readSource('app/Providers/PackageIntegration/FavoritesIntegrationServiceProvider.php');
        $legacyVendorNamespace = $this->legacyVendorNamespace();

        $this->assertStringContainsString('Shared\\Favorites\\Contracts\\AccountProfileFavoriteDirectReadContract', $source);
        $this->assertStringContainsString('AccountProfileFavoriteDirectReadService::class', $source);
        $this->assertStringNotContainsString('dispatchSync(', $source);
        $this->assertStringNotContainsString('RebuildFavoriteSnapshotJob', $source);
        $this->assertStringNotContainsString($legacyVendorNamespace.'\\Favorites', $source);
    }

    public function test_favorites_registry_config_no_longer_declares_snapshot_runtime_fields(): void
    {
        $source = $this->readSource('config/favorites.php');
        $legacyVendorToken = $this->legacyVendorToken();

        $this->assertStringContainsString("'default_registry_key' => 'account_profile'", $source);
        $this->assertStringNotContainsString("'snapshot_builder'", $source);
        $this->assertStringNotContainsString("'snapshot_collection'", $source);
        $this->assertStringNotContainsString("'requires_specific_indexes'", $source);
        $this->assertStringNotContainsString($legacyVendorToken, $source);
    }

    public function test_public_tenant_route_mounts_shared_favorites_controller(): void
    {
        $source = $this->readSource('routes/api/packages/project_tenant_public_api_v1/favorites.php');
        $legacyVendorNamespace = $this->legacyVendorNamespace();

        $this->assertStringContainsString('Shared\\Favorites\\Http\\Api\\v1\\Controllers\\FavoritesController', $source);
        $this->assertStringNotContainsString($legacyVendorNamespace.'\\Favorites', $source);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }

    private function legacyVendorNamespace(): string
    {
        return $this->legacyVendorToken();
    }

    private function legacyVendorToken(): string
    {
        return 'Bellu'.'ga';
    }
}
