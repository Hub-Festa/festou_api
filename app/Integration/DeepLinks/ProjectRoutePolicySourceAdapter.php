<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use RuntimeException;
use Shared\DeepLinks\Contracts\ProjectRoutePolicySourceContract;

class ProjectRoutePolicySourceAdapter implements ProjectRoutePolicySourceContract
{
    public function currentProjectRoutePolicy(): ?array
    {
        $path = self::configuredPath();
        if (! is_file($path)) {
            return null;
        }

        $raw = require $path;
        if (! is_array($raw)) {
            throw new RuntimeException(sprintf(
                'Project deep-link route policy config `%s` must return an array.',
                $path
            ));
        }

        return $raw;
    }

    public static function configuredPath(): string
    {
        $configured = trim((string) env(
            'DEEP_LINK_ROUTE_POLICY_CONFIG_FILE',
            '/opt/project/laravel/deep_link_route_policy.php'
        ));
        if ($configured === '') {
            return '/opt/project/laravel/deep_link_route_policy.php';
        }

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return base_path($configured);
    }
}
