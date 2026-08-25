<?php

declare(strict_types=1);

namespace Shared\Media\Support;

final readonly class MediaModelDefinition
{
    /**
     * @param  array<int, string>  $slots
     * @param  array<int, string>  $allowedExtensions
     */
    public function __construct(
        public string $legacyPublicPathPrefix,
        public string $canonicalPublicPathPrefix,
        public string $storageDirectory,
        public array $slots = ['avatar', 'cover'],
        public array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'],
    ) {}
}
