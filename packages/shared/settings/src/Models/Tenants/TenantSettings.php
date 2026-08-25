<?php

declare(strict_types=1);

namespace Shared\Settings\Models\Tenants;

use Shared\Settings\Models\SettingsDocument;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantSettings extends SettingsDocument
{
    use UsesTenantConnection;
}
