<?php

declare(strict_types=1);

namespace Baka\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class LightHouseCacheCleanUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public function __construct(
        protected Model $model
    ) {
    }

    public function handle(): void
    {
        if (method_exists($this->model, 'clearLightHouseCache')) {
            $this->model->clearLightHouseCache();
        }
    }
}
