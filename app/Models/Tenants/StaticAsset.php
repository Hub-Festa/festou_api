<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class StaticAsset extends Model
{
    use UsesTenantConnection, SoftDeletes;

    protected $table = 'static_assets';

    protected $fillable = [
        'name',
        'description',
        'category',
        'tags',
        'taxonomy_terms',
        'location',
        'priority',
        'is_active',
        'media',
        'badge',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'priority' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
