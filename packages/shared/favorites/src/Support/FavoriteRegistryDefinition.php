<?php

declare(strict_types=1);

namespace Shared\Favorites\Support;

final class FavoriteRegistryDefinition
{
    public function __construct(
        public readonly string $registryKey,
        public readonly string $targetType,
    ) {}
}
