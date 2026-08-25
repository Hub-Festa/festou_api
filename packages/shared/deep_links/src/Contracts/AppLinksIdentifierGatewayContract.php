<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Contracts;

interface AppLinksIdentifierGatewayContract
{
    public function identifierForPlatform(string $platform): ?string;

    public function hasIdentifierForPlatform(string $platform): bool;
}
