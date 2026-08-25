<?php

declare(strict_types=1);

namespace Tests\Fixtures\PublicWeb;

use App\Application\PublicWeb\ProjectPublicShellMetadataExtension;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\Request;

class InviteMetadataExtension implements ProjectPublicShellMetadataExtension
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
        $metadata = $this->metadataService->defaultMetadata('/invite');
        $inviteCode = trim((string) $request->query('code', ''));

        $metadata['title'] = 'Invite Landing | '.$metadata['site_name'];
        $metadata['description'] = $inviteCode === ''
            ? 'Open the invite landing flow from the configured project route.'
            : 'Open invite code '.$inviteCode.' from the configured project route.';
        $metadata['canonical_url'] = request()->getSchemeAndHttpHost().'/invite';

        return $metadata;
    }
}
