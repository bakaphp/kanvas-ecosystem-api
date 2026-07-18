<?php

declare(strict_types=1);

namespace Baka\Search\Jobs;

use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Jobs\MakeSearchable;
use Override;

/**
 * Scout resolves the engine via app(Apps::class), but a queue worker's bound app is NOT the tenant
 * that dispatched the job (and models like Users have no app relation to derive it from), so queued
 * indexing silently routes to the wrong / Null engine. We capture the tenant at dispatch and rebind
 * it before Scout resolves the engine.
 *
 * We rebind the APP ONLY — never overwriteAppService(), which also resets the Bouncer permission
 * scope to company 0. Scout can run inline on the sync queue while the caller holds a company-scoped
 * Bouncer scope (e.g. saving a Role mid permission-setup), and resetting it corrupts them.
 */
class TenantAwareMakeSearchable extends MakeSearchable
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
