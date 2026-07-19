<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Jobs\TenantAwareMakeSearchable;
use Baka\Search\Jobs\TenantAwareRemoveFromSearch;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Scout;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

/**
 * Queued Scout indexing resolves the engine via app(Apps::class), which in a worker is NOT the
 * dispatching tenant — so without capturing + rebinding the app, queued index writes silently
 * route to the wrong / Null engine. These lock the tenant capture + job registration.
 */
final class TenantAwareIndexingTest extends TestCase
{
    public function testScoutUsesTheTenantAwareJobs(): void
    {
        $this->assertSame(TenantAwareMakeSearchable::class, Scout::$makeSearchableJob);
        $this->assertSame(TenantAwareRemoveFromSearch::class, Scout::$removeFromSearchJob);
    }

    public function testMakeSearchableCapturesCurrentAppAtDispatch(): void
    {
        $app = app(Apps::class);

        $job = new TenantAwareMakeSearchable(new Collection());

        $this->assertSame($app->getId(), $job->appId);
    }

    public function testRemoveFromSearchCapturesCurrentAppAtDispatch(): void
    {
        $app = app(Apps::class);

        $job = new TenantAwareRemoveFromSearch(new Collection());

        $this->assertSame($app->getId(), $job->appId);
    }

    /**
     * The job rebinds the app for engine resolution but must NOT touch the Bouncer scope — Scout can
     * run inline on the sync queue while the caller holds a company-scoped scope (e.g. saving a Role
     * during permission setup). overwriteAppService() would reset it to company 0 and corrupt them.
     */
    public function testHandleDoesNotResetTheBouncerScope(): void
    {
        $scope = 'app_' . app(Apps::class)->getId() . '_company_999';
        Bouncer::scope()->to($scope);

        new TenantAwareMakeSearchable(new Collection())->handle();

        $this->assertSame($scope, Bouncer::scope()->get());
    }
}
