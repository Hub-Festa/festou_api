<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\StaticAsset;
use Illuminate\Pagination\LengthAwarePaginator;

class StaticAssetQueryService extends AbstractQueryService
{
    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = StaticAsset::query();

        return $this->buildPaginator($query, $queryParams, $includeArchived, $perPage)
            ->through(function (StaticAsset $asset): array {
                return [
                    'id' => (string) $asset->_id,
                    'name' => $asset->name,
                    'description' => $asset->description,
                    'category' => $asset->category,
                    'tags' => $asset->tags ?? [],
                    'taxonomy_terms' => $asset->taxonomy_terms ?? [],
                    'location' => $this->formatLocation($asset->location),
                    'priority' => $asset->priority,
                    'is_active' => (bool) ($asset->is_active ?? true),
                    'created_at' => $asset->created_at?->toJSON(),
                    'updated_at' => $asset->updated_at?->toJSON(),
                    'deleted_at' => $asset->deleted_at?->toJSON(),
                ];
            });
    }

    /**
     * @param mixed $location
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }

    protected function baseSearchableFields(): array
    {
        return (new StaticAsset())->getFillable();
    }

    protected function stringFields(): array
    {
        return ['name', 'description', 'category'];
    }

    protected function arrayFields(): array
    {
        return ['tags'];
    }

    protected function dateFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at'];
    }

    protected function extraSearchableFields(): array
    {
        return [];
    }
}
