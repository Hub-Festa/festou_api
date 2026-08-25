<?php

declare(strict_types=1);

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->get()->each(function (Tenant $tenant): void {
            $legacyAppDomains = $tenant->getAttribute('app_domains');

            if (! is_array($legacyAppDomains) || $legacyAppDomains === []) {
                return;
            }

            $tenant->makeCurrent();

            try {
                $androidIdentifier = $this->firstNonEmpty([$legacyAppDomains[0] ?? null]);
                $iosIdentifier = $this->firstNonEmpty([$legacyAppDomains[1] ?? null]);

                if ($androidIdentifier !== null) {
                    $this->upsertTypedDomain(
                        tenant: $tenant,
                        type: Tenant::DOMAIN_TYPE_APP_ANDROID,
                        identifier: $androidIdentifier,
                    );
                }

                if ($iosIdentifier !== null) {
                    $this->upsertTypedDomain(
                        tenant: $tenant,
                        type: Tenant::DOMAIN_TYPE_APP_IOS,
                        identifier: $iosIdentifier,
                    );
                }
            } finally {
                $tenant->forgetCurrent();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive rollback. Typed app identifiers remain canonical once backfilled.
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = strtolower(trim($candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function upsertTypedDomain(Tenant $tenant, string $type, string $identifier): void
    {
        $existing = $tenant->domains()
            ->withTrashed()
            ->where('type', $type)
            ->orderBy('created_at')
            ->first();

        if ($existing === null) {
            $tenant->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        if ($existing->trashed()) {
            $existing->restore();

            return;
        }

        $existing->save();
    }
};
