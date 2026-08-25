<?php

declare(strict_types=1);

namespace Shared\Settings\Merge;

use Shared\Settings\Contracts\SettingsMergePolicyContract;

class NamespacePatchMergePolicy implements SettingsMergePolicyContract
{
    public function merge(array $current, array $changes): array
    {
        $merged = $current;

        foreach ($changes as $path => $value) {
            data_set($merged, $path, $value);
        }

        return $merged;
    }
}
