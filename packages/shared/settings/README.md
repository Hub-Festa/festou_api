# Shared Settings Kernel (`shared/settings`)

Reusable settings-kernel package for Laravel host applications.

## Purpose

This package centralizes settings schema registration, validation, merge semantics, and Mongo persistence so host modules do not invent their own patch behavior.

## Scope

Owned by the package:
- schema registry
- schema validator
- conditional rules evaluator
- merge policy
- Mongo settings store
- tenant and landlord settings controllers
- tenant and landlord migrations

Not owned by the package:
- module-specific business logic
- host route registration
- host auth/tenant middleware decisions
- landlord on-behalf tenant resolution adapters

## Public API

The package is wired by host route files, not by a package route file.

## PATCH Contract

Endpoint: `PATCH /settings/values/{namespace}`

Payload must be a direct object/map. Namespace envelopes are rejected.

Rules:
- only provided keys are changed
- omitted keys remain untouched
- `null` clears only nullable fields
- `null` on a non-nullable field returns `422`
- arrays at the top level return `422`
- unknown field paths return `422`
- namespace not found in scope returns `404`
- missing ability returns `403`

## Schema Model

Namespaces are registered with `Shared\Settings\Support\SettingsNamespaceDefinition`.

Field types supported by the kernel:
- `boolean`
- `integer`
- `number`
- `string`
- `array`
- `object`
- `date`
- `datetime`
- `mixed`

Fields may also declare:
- nullability
- defaults
- read-only flags
- deprecation flags
- display metadata
- grouping metadata
- conditional visibility/enabled rules

## Internal Components

Contracts:
- `SettingsRegistryContract`
- `SettingsStoreContract`
- `SettingsSchemaValidatorContract`
- `SettingsMergePolicyContract`
- `TenantScopeContextContract`

Runtime service:
- `SettingsKernelService`

Implementations:
- registry: `InMemorySettingsRegistry`
- validator: `SettingsSchemaValidator`
- merge: `NamespacePatchMergePolicy`
- store: `MongoSettingsStore`

Models:
- `SettingsDocument`
- `Models\Tenants\TenantSettings`
- `Models\Landlord\LandlordSettings`

## Host Integration

Host apps must:
1. Load the service provider.
2. Bind `TenantScopeContextContract` when using landlord on-behalf tenant flows.
3. Register namespaces through `SettingsRegistryContract`.
4. Keep all writes on the kernel patch contract.
5. Apply the correct abilities for each namespace.

## Migrations and Operations

Included migrations:
- tenant: `database/migrations/2026_02_26_000700_create_settings_collection.php`
- landlord: `database/migrations_landlord/2026_02_26_000710_create_landlord_settings_collection.php`

Both migrations create or normalize the single root document and fail fast on multi-document drift.

## Validation

Recommended checks:
- `php artisan test`
- focused settings-kernel feature tests in the host app

## Non-Goals

- No module-specific settings business rules.
- No route registration inside the package.
- No implicit scope guessing.
