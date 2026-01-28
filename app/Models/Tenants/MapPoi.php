<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class MapPoi extends Model
{
    use UsesTenantConnection, SoftDeletes;

    protected $table = 'map_pois';

    protected $fillable = [
        'tenant_id',
        'ref_type',
        'ref_id',
        'name',
        'subtitle',
        'category',
        'tags',
        'taxonomy_terms',
        'priority',
        'location',
        'exact_key',
        'time_anchor_at',
        'media',
        'badge',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'priority' => 'int',
        'time_anchor_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
