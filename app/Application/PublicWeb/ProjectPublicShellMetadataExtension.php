<?php

declare(strict_types=1);

namespace App\Application\PublicWeb;

use Illuminate\Http\Request;

interface ProjectPublicShellMetadataExtension
{
    /**
     * @return array<string, string>
     */
    public function metadataFor(
        Request $request,
        string $routeId,
        string $requestedPath,
        ?string $segment,
    ): array;
}
