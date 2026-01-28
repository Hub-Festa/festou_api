<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantSettings extends Model
{
    use UsesTenantConnection;

    protected $table = 'settings';

    protected $fillable = [
        'profile_type_registry',
        'map_ui',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
