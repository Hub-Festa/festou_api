<?php

declare(strict_types=1);

namespace Shared\Settings\Contracts;

use Shared\Settings\Support\SettingsNamespaceDefinition;

interface SettingsStoreContract
{
    /**
     * @return array<string, mixed>
     */
    public function getNamespaceValue(string $scope, string $namespace): array;

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function mergeNamespace(
        string $scope,
        string $namespace,
        array $changes,
        SettingsNamespaceDefinition $definition
    ): array;
}
