<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileTypeSetProvider
{
    /**
     * @return array<int, string>
     */
    public function publiclyNavigableTypes(): array
    {
        return $this->normalizeTypes(config('favorites.publicly_navigable_profile_types', []));
    }

    public function isPubliclyNavigable(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->publiclyNavigableTypes(), true);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTypes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_values(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $value),
            static fn (string $entry): bool => $entry !== ''
        ))));
    }
}
