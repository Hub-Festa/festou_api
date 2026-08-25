<?php

declare(strict_types=1);

return [
    'version' => 1,
    'availability' => 'required_policy',
    'routes' => [
        [
            'route_id' => 'privacy_policy',
            'ingress_requirement' => 'public_shell_required',
            'public_shell_route_id' => 'privacy_policy',
            'roles' => ['continuation'],
            'query_keys' => [],
        ],
    ],
];
