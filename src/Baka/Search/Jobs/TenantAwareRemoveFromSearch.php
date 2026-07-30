<?php

declare(strict_types=1);

namespace Baka\Search\Jobs;

use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Override;

/**
 * Tenant-aware counterpart to {@see TenantAwareMakeSearchable} for the delete path — rebinds the app
 * only (never the Bouncer scope) so unindexing resolves the right engine without corrupting a caller
 * that runs it inline on the sync queue.
 */
class TenantAwareRemoveFromSearch extends RemoveFromSearch
{
    public ?int $appId = null;

    /**
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     */
    public function __construct($models)
    {
        parent::__construct($models);

        $this->appId = app()->bound(Apps::class) ? app(Apps::class)->getId() : null;
    }

    #[Override]
    public function handle(): void
    {
        if ($this->appId === null) {
            parent::handle();

            return;
        }

        $previous = app()->bound(Apps::class) ? app(Apps::class) : null;
        app()->instance(Apps::class, Apps::getById($this->appId));

        try {
            parent::handle();
        } finally {
            $previous !== null
                ? app()->instance(Apps::class, $previous)
                : app()->forgetInstance(Apps::class);
        }
    }
}
