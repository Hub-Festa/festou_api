<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ACCOUNT_PROFILE_SLUG_INDEX = 'public_shell_account_profiles_slug_lookup';
    private const EVENT_OCCURRENCE_ROUTE_INDEX = 'public_shell_event_occurrences_slug_published_lookup';

    public function up(): void
    {
        $database = DB::connection(config('multitenancy.tenant_database_connection_name', 'tenants'))
            ->getMongoDB();

        $database->selectCollection('account_profiles')->createIndex(
            ['slug' => 1],
            ['name' => self::ACCOUNT_PROFILE_SLUG_INDEX]
        );

        $database->selectCollection('event_occurrences')->createIndex(
            ['slug' => 1, 'is_event_published' => 1],
            ['name' => self::EVENT_OCCURRENCE_ROUTE_INDEX]
        );
    }

    public function down(): void
    {
        $database = DB::connection(config('multitenancy.tenant_database_connection_name', 'tenants'))
            ->getMongoDB();

        try {
            $database->selectCollection('account_profiles')
                ->dropIndex(self::ACCOUNT_PROFILE_SLUG_INDEX);
        } catch (\Throwable) {
            // no-op
        }

        try {
            $database->selectCollection('event_occurrences')
                ->dropIndex(self::EVENT_OCCURRENCE_ROUTE_INDEX);
        } catch (\Throwable) {
            // no-op
        }
    }
};
