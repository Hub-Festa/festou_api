<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

return new class extends Migration
{
    private const string PLACE_REF_INDEX = 'idx_event_occurrences_public_agenda_place_ref_v1';

    private const string PARTY_REF_INDEX = 'idx_event_occurrences_public_agenda_party_ref_v1';

    private const array PLACE_REF_KEYS = [
        'deleted_at' => 1,
        'is_event_published' => 1,
        'place_ref.type' => 1,
        'place_ref.id' => 1,
        'effective_ends_at' => 1,
        'starts_at' => 1,
        '_id' => 1,
    ];

    private const array PARTY_REF_KEYS = [
        'deleted_at' => 1,
        'is_event_published' => 1,
        'event_parties.party_ref_id' => 1,
        'effective_ends_at' => 1,
        'starts_at' => 1,
        '_id' => 1,
    ];

    public function up(): void
    {
        $collection = $this->collection();

        $this->recreateIndex($collection, self::PLACE_REF_INDEX, self::PLACE_REF_KEYS);
        $this->recreateIndex($collection, self::PARTY_REF_INDEX, self::PARTY_REF_KEYS);
    }

    public function down(): void
    {
        $collection = $this->collection();

        $this->dropIndexIfExists($collection, self::PLACE_REF_INDEX);
        $this->dropIndexIfExists($collection, self::PARTY_REF_INDEX);
    }

    private function collection(): Collection
    {
        return DB::connection(config('multitenancy.tenant_database_connection_name', 'tenant'))
            ->getCollection('event_occurrences');
    }

    /**
     * @param  array<string, int>  $keys
     */
    private function recreateIndex(Collection $collection, string $name, array $keys): void
    {
        $this->dropIndexIfExists($collection, $name);

        $collection->createIndex($keys, ['name' => $name]);
    }

    private function dropIndexIfExists(Collection $collection, string $name): void
    {
        try {
            $collection->dropIndex($name);
        } catch (Throwable) {
            // Fresh and partially migrated tenant databases may not have this index yet.
        }
    }
};
