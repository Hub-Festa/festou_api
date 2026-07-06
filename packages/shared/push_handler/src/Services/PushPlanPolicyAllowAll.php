<?php

declare(strict_types=1);

namespace Shared\PushHandler\Services;

use Shared\PushHandler\Contracts\PushPlanPolicyContract;
use Shared\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Shared\PushHandler\Models\Tenants\PushMessage;

class PushPlanPolicyAllowAll implements PushPlanPolicyContract, PushPlanPolicyDecisionContract
{
    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
    {
        return true;
    }

    public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array
    {
        return [
            'allowed' => true,
            'limit' => null,
            'current_used' => null,
            'requested' => $audienceSize,
            'remaining_after' => null,
            'period' => null,
            'reason' => null,
        ];
    }
}
