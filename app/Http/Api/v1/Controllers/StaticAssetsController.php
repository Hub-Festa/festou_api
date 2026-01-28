<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\StaticAssets\StaticAssetManagementService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Http\Api\v1\Requests\StaticAssetStoreRequest;
use App\Http\Api\v1\Requests\StaticAssetUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\StaticAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaticAssetsController extends Controller
{
    public function __construct(
        private readonly StaticAssetManagementService $managementService,
        private readonly StaticAssetQueryService $queryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15) ?: 15;

        $paginator = $this->queryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            $perPage
        );

        return response()->json($paginator->toArray());
    }

    public function store(StaticAssetStoreRequest $request): JsonResponse
    {
        $asset = $this->managementService->create($request->validated());

        return response()->json([
            'data' => $this->formatAsset($asset),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $assetId = (string) $request->route('asset_id');
        $asset = StaticAsset::query()->where('_id', $assetId)->firstOrFail();

        return response()->json([
            'data' => $this->formatAsset($asset),
        ]);
    }

    public function update(StaticAssetUpdateRequest $request): JsonResponse
    {
        $assetId = (string) $request->route('asset_id');
        $asset = StaticAsset::query()->where('_id', $assetId)->firstOrFail();
        $updated = $this->managementService->update($asset, $request->validated());

        return response()->json([
            'data' => $this->formatAsset($updated),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $assetId = (string) $request->route('asset_id');
        $asset = StaticAsset::query()->where('_id', $assetId)->firstOrFail();
        $this->managementService->delete($asset);

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $assetId = (string) $request->route('asset_id');
        $asset = StaticAsset::onlyTrashed()->where('_id', $assetId)->firstOrFail();
        $restored = $this->managementService->restore($asset);

        return response()->json([
            'data' => $this->formatAsset($restored),
        ]);
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $assetId = (string) $request->route('asset_id');
        $asset = StaticAsset::onlyTrashed()->where('_id', $assetId)->firstOrFail();
        $this->managementService->forceDelete($asset);

        return response()->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAsset(StaticAsset $asset): array
    {
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
}
