<?php

declare(strict_types=1);

namespace Shared\Favorites\Domain\Events;

final class FavoriteAdded
{
    public function __construct(
        public readonly string $ownerUserId,
        public readonly string $registryKey,
        public readonly string $targetType,
        public readonly string $targetId,
    ) {}
}
