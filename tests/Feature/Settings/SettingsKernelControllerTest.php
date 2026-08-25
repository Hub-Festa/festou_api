<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Laravel\Sanctum\Sanctum;
use Shared\Settings\Contracts\SettingsRegistryContract;
use Shared\Settings\Models\Landlord\LandlordSettings;
use Shared\Settings\Models\Tenants\TenantSettings;
use Shared\Settings\Support\SettingsNamespaceDefinition;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class SettingsKernelControllerTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        $registry = $this->app->make(SettingsRegistryContract::class);

        if ($registry->find('landlord_identity', 'landlord') === null) {
            $registry->register(new SettingsNamespaceDefinition(
                namespace: 'landlord_identity',
                scope: 'landlord',
                label: 'Landlord Identity',
                groupLabel: 'Core',
                ability: null,
                fields: [
                    'display_name' => [
                        'type' => 'string',
                        'nullable' => false,
                        'label' => 'Display Name',
                        'default' => 'Belluga',
                        'order' => 10,
                    ],
                ],
                order: 10,
                labelI18nKey: null,
                description: 'Host baseline namespace for landlord-scoped settings.',
                descriptionI18nKey: null,
                icon: null,
            ));
        }

        if ($registry->find('tenant_experience', 'tenant') === null) {
            $registry->register(new SettingsNamespaceDefinition(
                namespace: 'tenant_experience',
                scope: 'tenant',
                label: 'Tenant Experience',
                groupLabel: 'Core',
                ability: null,
                fields: [
                    'welcome_label' => [
                        'type' => 'string',
                        'nullable' => false,
                        'label' => 'Welcome Label',
                        'default' => 'Hello',
                        'order' => 10,
                    ],
                    'feature_enabled' => [
                        'type' => 'boolean',
                        'nullable' => false,
                        'label' => 'Feature Enabled',
                        'default' => false,
                        'order' => 20,
                    ],
                ],
                order: 10,
                labelI18nKey: null,
                description: 'Host baseline namespace for tenant-scoped settings.',
                descriptionI18nKey: null,
                icon: null,
            ));
        }

        LandlordSettings::query()->delete();
        TenantSettings::query()->delete();

        LandlordSettings::create([
            'landlord_identity' => [
                'display_name' => 'Belluga Ecosystem',
            ],
        ]);

        TenantSettings::create([
            'tenant_experience' => [
                'welcome_label' => 'Tenant Portal',
                'feature_enabled' => true,
            ],
        ]);

        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['*']);
    }

    public function test_landlord_settings_schema_and_values_routes_resolve(): void
    {
        $schema = $this->getJson("http://{$this->host}/admin/api/v1/settings/schema");
        $schema->assertOk();
        $schema->assertJsonPath('data.namespaces.0.namespace', 'landlord_identity');

        $values = $this->getJson("http://{$this->host}/admin/api/v1/settings/values");
        $values->assertOk();
        $values->assertJsonPath('data.landlord_identity.display_name', 'Belluga Ecosystem');
    }

    public function test_landlord_settings_patch_persists_values(): void
    {
        $response = $this->patchJson("http://{$this->host}/admin/api/v1/settings/values/landlord_identity", [
            'display_name' => 'Belluga Platform',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.display_name', 'Belluga Platform');
        $this->assertSame(
            'Belluga Platform',
            LandlordSettings::current()?->getAttribute('landlord_identity')['display_name']
        );
    }

    public function test_tenant_settings_schema_and_values_routes_resolve(): void
    {
        $schema = $this->getJson("{$this->base_tenant_api_admin}settings/schema");
        $schema->assertOk();
        $schema->assertJsonPath('data.namespaces.0.namespace', 'tenant_experience');

        $values = $this->getJson("{$this->base_tenant_api_admin}settings/values");
        $values->assertOk();
        $values->assertJsonPath('data.tenant_experience.welcome_label', 'Tenant Portal');
        $values->assertJsonPath('data.tenant_experience.feature_enabled', true);
    }

    public function test_landlord_can_read_and_patch_tenant_settings_through_tenant_slug_route(): void
    {
        $values = $this->getJson("http://{$this->host}/admin/api/v1/{$this->tenant->slug}/settings/values");
        $values->assertOk();
        $values->assertJsonPath('data.tenant_experience.welcome_label', 'Tenant Portal');

        $patch = $this->patchJson("http://{$this->host}/admin/api/v1/{$this->tenant->slug}/settings/values/tenant_experience", [
            'welcome_label' => 'Belluga Tenant',
            'feature_enabled' => false,
        ]);

        $patch->assertOk();
        $patch->assertJsonPath('data.welcome_label', 'Belluga Tenant');
        $patch->assertJsonPath('data.feature_enabled', false);
    }
}
