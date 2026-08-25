<?php

declare(strict_types=1);

namespace Shared\PushHandler\Contracts;

use App\Models\Tenants\AccountUser;
use Shared\PushHandler\Models\Tenants\PushMessage;

interface PushAudienceEligibilityContract
{
    /**
     * @param array<string, mixed> $audience
     * @param array<string, mixed> $context
     */
    public function isEligible(
        AccountUser $user,
        PushMessage $message,
        array $audience,
        array $context = []
    ): bool;
}
