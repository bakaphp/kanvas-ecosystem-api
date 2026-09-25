<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Models;

use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\Concerns\CachesModelQueries;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class CachesModelQueriesTest extends TestCase
{
    public function testCachableModelsUseTheTraitThatDoesNotDoubleFlush(): void
    {
        $this->assertContains(
            CachesModelQueries::class,
            class_uses_recursive(SystemModules::class),
            'SystemModules must not go back to the upstream Cachable trait'
        );
    }

    /**
     * Laravel fires `created` and then `saved` on a single insert. Upstream binds both and runs a
     * full tagged-cache flush from each, so one row costs two invalidations — ~120ms against Redis.
     * `saved` alone covers inserts and updates.
     */
    public function testAnInsertInvalidatesTheCacheOnceNotTwice(): void
    {
        $flushes = $this->countFlushes(function (): void {
            SystemModules::create([
                'model_name' => 'Tests\\Caching\\Insert' . uniqid(),
                'apps_id' => app(Apps::class)->getKey(),
                'slug' => 'tests-caching-insert',
            ]);
        });

        $this->assertSame(1, $flushes);
    }

    public function testAnUpdateStillInvalidatesTheCache(): void
    {
        $module = SystemModules::create([
            'model_name' => 'Tests\\Caching\\Update' . uniqid(),
            'apps_id' => app(Apps::class)->getKey(),
            'slug' => 'tests-caching-update',
        ]);

        $flushes = $this->countFlushes(function () use ($module): void {
            $module->update(['name' => 'renamed']);
        });

        $this->assertGreaterThan(0, $flushes, 'dropping the created hook must not stop updates invalidating');
    }

    public function testADeleteStillInvalidatesTheCache(): void
    {
        $module = SystemModules::create([
            'model_name' => 'Tests\\Caching\\Delete' . uniqid(),
            'apps_id' => app(Apps::class)->getKey(),
            'slug' => 'tests-caching-delete',
        ]);

        $flushes = $this->countFlushes(function () use ($module): void {
            $module->delete();
        });

        $this->assertGreaterThan(0, $flushes, 'dropping the created hook must not stop deletes invalidating');
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
