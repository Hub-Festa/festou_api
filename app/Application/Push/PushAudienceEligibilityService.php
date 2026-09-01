<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushAudienceEligibilityService implements PushAudienceEligibilityContract
{
    /**
     * @param  array<string, mixed>  $audience
     * @param  array<string, mixed>  $context
     */
    public function isEligible(
        Authenticatable $user,
        PushMessage $message,
        array $audience,
        array $context = []
    ): bool {
        if (! $user instanceof AccountUser) {
            return false;
        }

        $type = trim((string) ($audience['type'] ?? ''));
        $userId = (string) $user->_id;

        if ($type === 'users') {
            $ids = is_array($audience['user_ids'] ?? null) ? $audience['user_ids'] : [];

            return in_array($userId, $ids, true);
        }

        if ($type === 'all_users') {
            return true;
        }

        return false;
    }
}
