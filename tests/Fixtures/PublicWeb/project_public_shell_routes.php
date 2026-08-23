<?php

declare(strict_types=1);

return [
    'extensions' => [
        [
            'extension_id' => 'invite_metadata_test',
            'service_container_id' => Tests\Fixtures\PublicWeb\InviteMetadataExtension::class,
            'supported_shapes' => ['exact'],
        ],
        [
            'extension_id' => 'static_asset_metadata_test',
            'service_container_id' => Tests\Fixtures\PublicWeb\StaticAssetMetadataExtension::class,
            'supported_shapes' => ['one_segment'],
        ],
    ],
    'routes' => [
        [
            'route_id' => 'discover_landing',
            'path' => '/descobrir',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'map_landing',
            'path' => '/mapa',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'map_poi_landing',
            'path' => '/mapa/poi',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'invite_landing',
            'path' => '/invite',
            'shape' => 'exact',
            'semantic' => 'invite_metadata_test',
        ],
        [
            'route_id' => 'invites_landing',
            'path' => '/convites',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'location_permission',
            'path' => '/location/permission',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'promotion_fallback',
            'path' => '/baixe-o-app',
            'shape' => 'exact',
            'semantic' => 'shell',
        ],
        [
            'route_id' => 'partner_detail',
            'path' => '/parceiro/',
            'shape' => 'one_segment',
            'semantic' => 'account_profile_metadata',
        ],
        [
            'route_id' => 'event_detail',
            'path' => '/agenda/evento/',
            'shape' => 'one_segment',
            'semantic' => 'event_metadata',
        ],
        [
            'route_id' => 'static_asset_detail',
            'path' => '/static/',
            'shape' => 'one_segment',
            'semantic' => 'static_asset_metadata_test',
        ],
    ],
];
