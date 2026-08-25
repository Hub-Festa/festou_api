<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use App\Models\Landlord\Tenant;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;

class AppLinksIdentifierGatewayAdapter implements AppLinksIdentifierGatewayContract
{
    public function identifierForPlatform(string $platform): ?string
    {
        $tenant = Tenant::current()?->fresh();
        if ($tenant === null) {
            return null;
        }

        $identifier = $tenant->appDomainIdentifierForPlatform($platform);
        if (! is_string($identifier)) {
            return null;
        }

        $normalized = trim($identifier);

        return $normalized === '' ? null : $normalized;
    }

    public function hasIdentifierForPlatform(string $platform): bool
    {
        return $this->identifierForPlatform($platform) !== null;
    }
}
