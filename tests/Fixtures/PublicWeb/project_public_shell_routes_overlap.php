<?php

declare(strict_types=1);

return [
    'extensions' => [],
    'routes' => [
        [
            'route_id' => 'map_prefix',
            'path' => '/mapa/',
            'shape' => 'one_segment',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'map_poi_exact',
            'path' => '/mapa/poi',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
    ],
];
