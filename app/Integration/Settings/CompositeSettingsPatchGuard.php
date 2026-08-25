<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Integration\DeepLinks\AppLinksPatchGuard;
use Shared\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Shared\Settings\Support\SettingsNamespaceDefinition;

class CompositeSettingsPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly AppLinksPatchGuard $appLinksPatchGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {
        $this->appLinksPatchGuard->guard($scope, $user, $namespace, $payload, $definition);
    }
}
