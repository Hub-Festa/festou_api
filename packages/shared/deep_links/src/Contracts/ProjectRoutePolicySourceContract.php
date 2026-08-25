<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Contracts;

interface ProjectRoutePolicySourceContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function currentProjectRoutePolicy(): ?array;
}
