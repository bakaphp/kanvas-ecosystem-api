<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Kanvas\Apps\Models\Apps;

/**
 * The using command must declare an `--app=` option.
 */
trait IteratesTargetApps
{
    /**
     * @return iterable<Apps>
     */
    protected function targetApps(): iterable
    {
        $appId = $this->option('app');

        if ($appId !== null) {
            return [Apps::getById((int) $appId)];
        }

        return Apps::query()->where('is_deleted', 0)->cursor();
    }
}
