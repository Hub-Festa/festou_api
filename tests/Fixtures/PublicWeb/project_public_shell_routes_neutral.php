<?php

declare(strict_types=1);

return [
    'extensions' => [],
    'routes' => [
        [
            'route_id' => 'project_landing',
            'path' => '/project-entry',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'project_profile',
            'path' => '/project-profiles/',
            'shape' => 'one_segment',
            'semantic' => 'account_profile_metadata',
        ],
        [
            'route_id' => 'project_event',
            'path' => '/project-events/',
            'shape' => 'one_segment',
            'semantic' => 'event_metadata',
        ],
    ],
];
