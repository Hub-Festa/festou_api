<?php

declare(strict_types=1);

namespace Shared\PushHandler\Contracts;

use Shared\PushHandler\Models\Tenants\PushMessage;

interface FcmClientContract
{
    /**
     * @param array<int, string> $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens): array;
}
