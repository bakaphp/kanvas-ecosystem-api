<?php

declare(strict_types=1);

namespace Baka\Jobs;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class LightHouseCacheCleanUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public $uniqueFor = 300;
    public $tries = 1;

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

    public function uniqueId(): string
    {
        return Str::simpleSlug(get_class($this->model) . '-' . $this->model->getKey());
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter($this->uniqueFor),
        ];
    }
}
