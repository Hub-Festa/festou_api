<?php

declare(strict_types=1);

namespace Shared\PushHandler\Contracts;

use Shared\PushHandler\Models\Tenants\PushMessage;

interface PushPlanPolicyDecisionContract
{
    /**
     * @return array{
     *   allowed: bool,
     *   limit: int|null,
     *   current_used: int|null,
     *   requested: int,
     *   remaining_after: int|null,
     *   period: string|null,
     *   reason: string|null
     * }
     */
    public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array;
}
