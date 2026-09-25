<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Models;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionMethod;
use Tests\TestCase;

final class PruneModelCacheTagsCommandTest extends TestCase
{
    public function testItReclaimsTheEntriesATagFlushLeavesBehind(): void
    {
        $this->markSkippedUnlessRedisBacked();

        $tags = $this->systemModuleTags();
        Cache::store('model')->flush();

        foreach (['Prune\\A', 'Prune\\B', 'Prune\\C'] as $name) {
            SystemModules::where('model_name', $name)->where('apps_id', app(Apps::class)->getKey())->first();
        }

        $populated = $this->entryCount($tags);
        $this->assertGreaterThan(0, $populated, 'the cached reads should have written tag entries');

        // A tag flush deletes the cached values but leaves their index entries dangling — this is the
        // leak that makes every subsequent flush more expensive.
        Cache::store('model')->tags($tags)->flush();
        $this->assertSame($populated, $this->entryCount($tags), 'the flush is expected to leave the index behind');

        $this->artisan('kanvas:cache:prune-model-cache-tags')->assertSuccessful();

        $this->assertSame(0, $this->entryCount($tags));
    }

    public function testItLeavesLiveEntriesAlone(): void
    {
        $this->markSkippedUnlessRedisBacked();

        $tags = $this->systemModuleTags();
        Cache::store('model')->flush();

        SystemModules::where('model_name', 'Prune\\Live')->where('apps_id', app(Apps::class)->getKey())->first();
        $live = $this->entryCount($tags);

        $this->artisan('kanvas:cache:prune-model-cache-tags')->assertSuccessful();

        $this->assertSame($live, $this->entryCount($tags), 'entries whose cache key still exists must survive');
    }

    public function testTheDryRunRemovesNothing(): void
    {
        $this->markSkippedUnlessRedisBacked();

        $tags = $this->systemModuleTags();
        Cache::store('model')->flush();

        SystemModules::where('model_name', 'Prune\\Dry')->where('apps_id', app(Apps::class)->getKey())->first();
        Cache::store('model')->tags($tags)->flush();
        $dangling = $this->entryCount($tags);

        $this->artisan('kanvas:cache:prune-model-cache-tags', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($dangling, $this->entryCount($tags));
    }

    /**
     * @return array<int, string>
     */
    private function systemModuleTags(): array
    {
        return new ReflectionMethod(SystemModules::class, 'makeCacheTags')->invoke(new SystemModules());
    }

    private function entryCount(array $tags): int
    {
        return Cache::store('model')->tags($tags)->getTags()->entries()->count();
    }

    private function markSkippedUnlessRedisBacked(): void
    {
        if (! method_exists(Cache::store('model')->getStore(), 'connection')) {
            $this->markTestSkipped('The model cache store is not Redis backed.');
        }
    }
}
