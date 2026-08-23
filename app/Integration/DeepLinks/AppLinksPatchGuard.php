<?php

declare(strict_types=1);

namespace App\Integration\DeepLinks;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\Settings\Contracts\SettingsNamespacePatchGuardContract;
use Shared\Settings\Support\SettingsNamespaceDefinition;

class AppLinksPatchGuard implements SettingsNamespacePatchGuardContract
{
    public function __construct(
        private readonly AppLinksIdentifierGatewayContract $identifierGateway,
        private readonly AppLinksSettingsSourceContract $settingsSource,
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
        if ($namespace !== 'app_links') {
            return;
        }

        $normalizedPatch = $this->normalizePatchPayload($payload, $definition->namespace);
        $errors = [];

        if ($this->containsRetiredPathAuthority($normalizedPatch)) {
            $errors['ios.paths'][] = 'Project deep-link route policy owns Apple association paths; app_links.ios.paths is retired.';
        }

        if ($scope !== 'tenant') {
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        $current = $this->settingsSource->currentAppLinksSettings();
        foreach ($normalizedPatch as $path => $value) {
            Arr::set($current, $path, $value);
        }

        $fingerprints = $this->normalizeFingerprints(data_get($current, 'android.sha256_cert_fingerprints', []));
        if ($fingerprints !== [] && ! $this->identifierGateway->hasIdentifierForPlatform('android')) {
            $errors['android.sha256_cert_fingerprints'][] = 'Configure Android app identifier before saving fingerprints.';
        }

        $iosTeamId = $this->normalizeText(data_get($current, 'ios.team_id'));
        if ($iosTeamId !== null && ! $this->identifierGateway->hasIdentifierForPlatform('ios')) {
            $errors['ios.team_id'][] = 'Configure iOS app identifier before saving team_id.';
        }

        $androidStoreUrl = $this->normalizeText(data_get($current, 'android.store_url'));
        if ($androidStoreUrl !== null && ! $this->isValidAbsoluteHttpUrl($androidStoreUrl)) {
            $errors['android.store_url'][] = 'Android store URL must be an absolute http(s) URL.';
        }
        if ($this->normalizeBoolean(data_get($current, 'android.enabled')) && $androidStoreUrl === null) {
            $errors['android.store_url'][] = 'Android store URL is required when Android publication is active.';
        }

        $iosStoreUrl = $this->normalizeText(data_get($current, 'ios.store_url'));
        if ($iosStoreUrl !== null && ! $this->isValidAbsoluteHttpUrl($iosStoreUrl)) {
            $errors['ios.store_url'][] = 'iOS store URL must be an absolute http(s) URL.';
        }
        if ($this->normalizeBoolean(data_get($current, 'ios.enabled')) && $iosStoreUrl === null) {
            $errors['ios.store_url'][] = 'iOS store URL is required when iOS publication is active.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePatchPayload(array $payload, string $namespace): array
    {
        $normalized = [];

        foreach (Arr::dot(Arr::undot($payload)) as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $trimmed = trim($key);
            $prefix = $namespace.'.';
            if (str_starts_with($trimmed, $prefix)) {
                $trimmed = substr($trimmed, strlen($prefix));
            }

            $normalized[$trimmed] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $normalizedPatch
     */
    private function containsRetiredPathAuthority(array $normalizedPatch): bool
    {
        foreach (array_keys($normalizedPatch) as $path) {
            if (! is_string($path)) {
                continue;
            }

            if ($path === 'ios.paths' || str_starts_with($path, 'ios.paths.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFingerprints(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $candidate = strtoupper(trim($entry));
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function isValidAbsoluteHttpUrl(string $value): bool
    {
        $url = filter_var($value, FILTER_VALIDATE_URL);
        if ($url === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
