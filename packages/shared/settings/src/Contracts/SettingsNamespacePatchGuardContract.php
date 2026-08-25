<?php

declare(strict_types=1);

namespace Shared\Settings\Contracts;

use Shared\Settings\Support\SettingsNamespaceDefinition;

interface SettingsNamespacePatchGuardContract
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
    ): void;
}
