<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

class TaxonomySnapshotBackfillService
{
    /**
     * Snapshot-owning taxonomy consumers are not materialized in this
     * boilerplate yet, so the successor keeps a no-op placeholder seam.
     *
     * @return array<string, mixed>
     */
    public function repair(?string $taxonomyType = null, ?string $termValue = null): array
    {
        return [
            'taxonomy_type' => $taxonomyType,
            'term_value' => $termValue,
            'status' => 'noop',
            'totals' => [
                'scanned' => 0,
                'updated' => 0,
                'failed' => 0,
            ],
        ];
    }
}
