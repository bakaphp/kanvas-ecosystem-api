<?php

declare(strict_types=1);

namespace Baka\Jobs;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LightHouseCacheCleanUpJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public $tries = 2;
    public $backoff = [30, 60];
    public $deleteWhenMissingModels = true;
    public $uniqueFor = 300;

    public function __construct(
        protected Model $model
    ) {
    }

    public function handle(): void
    {
        if (! $this->model->exists) {
            return;
        }

        if (method_exists($this->model, 'clearLightHouseCache')) {
            $this->model->clearLightHouseCache();
        }
    }

    public function uniqueId(): string
    {
        return Str::simpleSlug(get_class($this->model) . '-' . $this->model->getKey());
    }

    public function failed(?Throwable $exception): void
    {
    }
}
