<?php

declare(strict_types=1);

namespace Shared\Settings\Contracts;

use Shared\Settings\Support\SettingsNamespaceDefinition;

interface SettingsRegistryContract
{
    public function register(SettingsNamespaceDefinition $definition): void;

    /**
     * @return array<int, SettingsNamespaceDefinition>
     */
    public function all(?string $scope = null): array;

    public function find(string $namespace, ?string $scope = null): ?SettingsNamespaceDefinition;
}
