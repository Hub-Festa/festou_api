<?php

declare(strict_types=1);

namespace Shared\Settings\Contracts;

use Shared\Settings\Support\SettingsNamespaceDefinition;

interface SettingsSchemaValidatorContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validatePatch(SettingsNamespaceDefinition $definition, array $payload): array;
}
