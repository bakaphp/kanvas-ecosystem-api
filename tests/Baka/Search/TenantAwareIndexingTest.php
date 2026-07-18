<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Jobs\TenantAwareMakeSearchable;
use Baka\Search\Jobs\TenantAwareRemoveFromSearch;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Scout;
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
}
