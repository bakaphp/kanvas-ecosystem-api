<?php

declare(strict_types=1);

namespace Baka\Search\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Override;

/**
 * Tenant-aware counterpart to {@see TenantAwareMakeSearchable} for the delete path — same reason:
 * the worker's bound app is not the dispatching tenant, so unindexing must rebind first.
 */
class TenantAwareRemoveFromSearch extends RemoveFromSearch
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
