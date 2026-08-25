<?php

declare(strict_types=1);

namespace Shared\Settings\Models\Landlord;

use Shared\Settings\Models\SettingsDocument;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordSettings extends SettingsDocument
{
    use UsesLandlordConnection;
}
