<?php

declare(strict_types=1);

return [
    'extensions' => [
        [
            'extension_id' => 'duplicate_extension',
            'service_container_id' => Tests\Fixtures\PublicWeb\InviteMetadataExtension::class,
            'supported_shapes' => ['exact'],
        ],
        [
            'extension_id' => 'duplicate_extension',
            'service_container_id' => Tests\Fixtures\PublicWeb\StaticAssetMetadataExtension::class,
            'supported_shapes' => ['one_segment'],
        ],
    ],
    'routes' => [],
];
