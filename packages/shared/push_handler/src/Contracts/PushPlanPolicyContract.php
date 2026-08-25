<?php

declare(strict_types=1);

namespace Shared\PushHandler\Contracts;

use Shared\PushHandler\Models\Tenants\PushMessage;

interface PushPlanPolicyContract
{
    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool;
}
