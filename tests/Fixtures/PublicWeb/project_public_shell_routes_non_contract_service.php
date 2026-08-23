<?php

declare(strict_types=1);

return [
    'extensions' => [
        [
            'extension_id' => 'plain_object_extension',
            'service_container_id' => Tests\Fixtures\PublicWeb\PlainObjectExtension::class,
            'supported_shapes' => ['exact'],
        ],
    ],
    'routes' => [],
];
