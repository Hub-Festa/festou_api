<?php

declare(strict_types=1);

namespace Tests\Fixtures\PublicWeb;

use App\Application\PublicWeb\ProjectPublicShellMetadataExtension;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\Request;

class StaticAssetMetadataExtension implements ProjectPublicShellMetadataExtension
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
    ) {}

    public function metadataFor(
        Request $request,
        string $routeId,
        string $requestedPath,
        ?string $segment,
    ): array {
        $assetRef = trim((string) $segment);
        $metadata = $this->metadataService->defaultMetadata('/static/'.$assetRef);
        $label = trim(ucwords(str_replace(['-', '_'], ' ', $assetRef)));
        if ($label === '') {
            $label = 'Static Asset';
        }

        $metadata['title'] = $label.' | '.$metadata['site_name'];
        $metadata['description'] = 'Configured static-asset metadata for '.$label.'.';
        $metadata['canonical_url'] = request()->getSchemeAndHttpHost().'/static/'.$assetRef;

        return $metadata;
    }
}
