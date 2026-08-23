<?php

declare(strict_types=1);

return [
    'extensions' => [
        [
            'extension_id' => 'missing_binding_extension',
            'service_container_id' => 'missing.public_shell.extension',
            'supported_shapes' => ['exact'],
        ],
    ],
    'routes' => [
        [
            'route_id' => 'missing_binding_route',
            'path' => '/missing-binding',
            'shape' => 'exact',
            'semantic' => 'missing_binding_extension',
        ],
    ],
];
