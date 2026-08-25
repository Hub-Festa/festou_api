<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Contracts;

interface PublicShellRouteInventorySourceContract
{
    /**
     * @return list<array<string, mixed>>
     */
    public function currentPublicShellRouteInventory(): array;
}
