<?php

declare(strict_types=1);

namespace Tests\Unit\DeepLinks;

use App\Integration\DeepLinks\AppLinksPatchGuard;
use Shared\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Shared\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Shared\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppLinksPatchGuardTest extends TestCase
{
    public function testGuardBlocksAndroidFingerprintsWithoutAndroidIdentifier(): void
    {
        $guard = new AppLinksPatchGuard(
            new class implements AppLinksIdentifierGatewayContract {
                public function identifierForPlatform(string $platform): ?string
                {
                    return null;
                }

                public function hasIdentifierForPlatform(string $platform): bool
                {
                    return false;
                }
            },
            new class implements AppLinksSettingsSourceContract {
                public function currentAppLinksSettings(): array
                {
                    return [];
                }
            },
        );

        $definition = new SettingsNamespaceDefinition(
            namespace: 'app_links',
            scope: 'tenant',
            label: 'App Links',
            groupLabel: 'Mobile',
            ability: null,
            fields: [
                'android.sha256_cert_fingerprints' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Android SHA-256 Fingerprints',
                    'default' => [],
                ],
                'android.enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Android Published',
                    'default' => false,
                ],
                'android.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Android Store URL',
                    'default' => null,
                ],
                'ios.team_id' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Apple Team ID',
                    'default' => null,
                ],
                'ios.paths' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'iOS Universal Link Paths',
                    'default' => ['/invite*'],
                ],
                'ios.enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'iOS Published',
                    'default' => false,
                ],
                'ios.store_url' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'iOS Store URL',
                    'default' => null,
                ],
            ],
        );

        $this->expectException(ValidationException::class);
        $guard->guard('tenant', null, 'app_links', [
            'android' => [
                'sha256_cert_fingerprints' => ['AB:CD'],
            ],
        ], $definition);
    }
}
