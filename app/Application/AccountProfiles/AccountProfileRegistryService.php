<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\DataObjects\Settings\ProfileTypeRegistrySettings;
use App\Models\Tenants\TenantSettings;

class AccountProfileRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return ProfileTypeRegistrySettings::fromValue(
            TenantSettings::current()?->getAttribute('profile_type_registry')
        )->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function typeDefinition(string $profileType): ?array
    {
        foreach ($this->registry() as $entry) {
            if (($entry['type'] ?? null) === $profileType) {
                return $entry;
            }
        }

        return null;
    }

    public function isPoiEnabled(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return (bool) ($capabilities['is_poi_enabled'] ?? false);
    }
}
