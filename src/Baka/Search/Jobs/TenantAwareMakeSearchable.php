<?php

declare(strict_types=1);

namespace Baka\Search\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Jobs\MakeSearchable;
use Override;

/**
 * Scout's MakeSearchable resolves the engine via app(Apps::class), but the queue worker's bound app
 * is NOT the tenant that dispatched the job (and models like Users have no app relation to derive it
 * from). So queued indexing silently routes to the wrong / Null engine. We capture the tenant at
 * dispatch time and rebind it in the worker before Scout resolves the engine.
 */
class TenantAwareMakeSearchable extends MakeSearchable
{
    use KanvasJobsTrait;

    public ?int $appId = null;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function __construct($models)
    {
        parent::__construct($models);

        $this->appId = app()->bound(Apps::class) ? app(Apps::class)->getId() : null;
    }

    #[Override]
    public function handle(): void
    {
        if ($this->appId !== null) {
            $this->overwriteAppService(Apps::getById($this->appId));
        }

        parent::handle();
    }
}
