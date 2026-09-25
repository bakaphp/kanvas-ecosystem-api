<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Models;

use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\Concerns\CachesModelQueries;
use Tests\TestCase;

final class CachesModelQueriesTest extends TestCase
{
    public function testCachableModelsUseTheTraitThatDoesNotDoubleFlush(): void
    {
        $this->assertContains(
            CachesModelQueries::class,
            class_uses_recursive(Apps::class),
            'Apps must not go back to the upstream Cachable trait'
        );
    }

    /**
     * Laravel fires `created` and then `saved` on a single insert. Upstream binds both and runs a
     * full tagged-cache flush from each, so one row costs two invalidations — ~120ms against Redis.
     * `saved` alone covers inserts and updates.
     */
    public function testAnInsertInvalidatesTheCacheOnceNotTwice(): void
    {
        $app = null;
        $flushes = $this->countFlushes(function () use (&$app): void {
            $app = $this->makeApp('Insert');
        });

        $app->forceDelete();
        $this->assertSame(1, $flushes);
    }

    public function testAnUpdateStillInvalidatesTheCache(): void
    {
        $app = $this->makeApp('Update');

        $flushes = $this->countFlushes(function () use ($app): void {
            $app->update(['name' => 'renamed']);
        });

        $app->forceDelete();

        $this->assertGreaterThan(0, $flushes, 'dropping the created hook must not stop updates invalidating');
    }

    public function testADeleteStillInvalidatesTheCache(): void
    {
        $app = $this->makeApp('Delete');

        $flushes = $this->countFlushes(function () use ($app): void {
            $app->delete();
        });

        $app->forceDelete();

        $this->assertGreaterThan(0, $flushes, 'dropping the created hook must not stop deletes invalidating');
    }

    private function makeApp(string $tag): Apps
    {
        $app = new Apps();
        $app->fill([
            'name' => 'Caching ' . $tag . ' ' . uniqid(),
            'url' => 'https://caching.test',
            'description' => 'test',
            'domain' => 'caching.test',
            'is_actived' => 1,
            'ecosystem_auth' => 1,
            'payments_active' => 0,
            'is_public' => 0,
            'domain_based' => 0,
        ]);
        $app->saveOrFail();

        return $app;
    }

    private function countFlushes(callable $operation): int
    {
        $flushes = 0;
        Event::listen(CacheFlushing::class, function () use (&$flushes) {
            $flushes++;
        });

        $operation();

        Event::forget(CacheFlushing::class);

        return $flushes;
    }
}
