<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Kanvas\Apps\Models\Apps;

/**
 * The using command must declare an `--app=` option.
 */
trait ResolvesAppOption
{
    private function resolveApp(): ?Apps
    {
        $appId = $this->option('app');

        return $appId === null ? null : Apps::query()->where('id', (int) $appId)->first();
    }
}
