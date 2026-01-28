<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Jobs\RemoveMapPoiByReferenceJob;
use App\Jobs\UpsertMapPoiForStaticAssetJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use Illuminate\Support\Facades\DB;

class StaticAssetManagementService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): StaticAsset
    {
        $asset = DB::connection('tenant')->transaction(function () use ($payload): StaticAsset {
            if (! array_key_exists('is_active', $payload)) {
                $payload['is_active'] = true;
            }
            if (array_key_exists('location', $payload)) {
                $payload['location'] = $this->formatLocation($payload['location']);
            }

            return StaticAsset::create($payload)->fresh();
        });

        $this->dispatchUpsert($asset);

        return $asset;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(StaticAsset $asset, array $payload): StaticAsset
    {
        if (array_key_exists('location', $payload)) {
            $payload['location'] = $this->formatLocation($payload['location']);
        }

        $asset->fill($payload);
        $asset->save();

        $this->dispatchUpsert($asset->fresh());

        return $asset->fresh();
    }

    public function delete(StaticAsset $asset): void
    {
        $asset->delete();

        $this->dispatchRemove($asset);
    }

    public function restore(StaticAsset $asset): StaticAsset
    {
        $asset->restore();

        $fresh = $asset->fresh();
        if ($fresh) {
            $this->dispatchUpsert($fresh);
        }

        return $fresh ?? $asset;
    }

    public function forceDelete(StaticAsset $asset): void
    {
        $asset->forceDelete();

        $this->dispatchRemove($asset);
    }

    private function dispatchUpsert(StaticAsset $asset): void
    {
        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        UpsertMapPoiForStaticAssetJob::dispatchSync((string) $tenant->_id, (string) $asset->_id);
    }

    private function dispatchRemove(StaticAsset $asset): void
    {
        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        RemoveMapPoiByReferenceJob::dispatchSync((string) $tenant->_id, 'static', (string) $asset->_id);
    }

    /**
     * @param mixed $location
     * @return array<string, mixed>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [(float) $lng, (float) $lat],
        ];
    }
}
