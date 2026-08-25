<?php

declare(strict_types=1);

namespace Shared\Settings\Validation;

use Shared\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Shared\Settings\Support\SettingsNamespaceDefinition;

final class NoopSettingsNamespacePatchGuard implements SettingsNamespacePatchGuardContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function guard(
        string $scope,
        mixed $user,
        string $namespace,
        array $payload,
        SettingsNamespaceDefinition $definition,
    ): void {}
}
