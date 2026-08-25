<?php

declare(strict_types=1);

return [
    'extensions' => [
        [
            'extension_id' => 'exact_only_extension',
            'service_container_id' => Tests\Fixtures\PublicWeb\InviteMetadataExtension::class,
            'supported_shapes' => ['exact'],
        ],
    ],
    'routes' => [
        [
            'route_id' => 'unsupported_shape_route',
            'path' => '/campaigns/',
            'shape' => 'one_segment',
            'semantic' => 'exact_only_extension',
        ],
    ],
];
