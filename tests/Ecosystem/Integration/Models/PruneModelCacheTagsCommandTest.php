<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Models;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use ReflectionMethod;
use Tests\TestCase;

final class PruneModelCacheTagsCommandTest extends TestCase
{
    public function testItReclaimsTheEntriesATagFlushLeavesBehind(): void
    {
        $this->markSkippedUnlessModelCacheIsLive();

        $tags = $this->appTags();
        Cache::store('model')->flush();

        foreach (['prune-a', 'prune-b', 'prune-c'] as $key) {
            Apps::where('key', $key)->first();
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
        $this->markSkippedUnlessModelCacheIsLive();

        $tags = $this->appTags();
        Cache::store('model')->flush();

        Apps::where('key', 'prune-live')->first();
        $live = $this->entryCount($tags);

        $this->artisan('kanvas:cache:prune-model-cache-tags')->assertSuccessful();

        $this->assertSame($live, $this->entryCount($tags), 'entries whose cache key still exists must survive');
    }

    public function testTheDryRunRemovesNothing(): void
    {
        $this->markSkippedUnlessModelCacheIsLive();

        $tags = $this->appTags();
        Cache::store('model')->flush();

        Apps::where('key', 'prune-dry')->first();
        Cache::store('model')->tags($tags)->flush();
        $dangling = $this->entryCount($tags);

        $this->artisan('kanvas:cache:prune-model-cache-tags', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($dangling, $this->entryCount($tags));
    }

    /**
     * @return array<int, string>
     */
    private function appTags(): array
    {
        return new ReflectionMethod(Apps::class, 'makeCacheTags')->invoke(new Apps());
    }

    private function entryCount(array $tags): int
    {
        return Cache::store('model')->tags($tags)->getTags()->entries()->count();
    }

    /**
     * CI runs with MODEL_CACHE_ENABLED=false, so nothing is ever cached and there is no tag index to
     * prune. Without this the tests do not just skip, they assert against an index that cannot exist.
     */
    private function markSkippedUnlessModelCacheIsLive(): void
    {
        if (! config('laravel-model-caching.enabled')) {
            $this->markTestSkipped('Model caching is disabled in this environment.');
        }

        if (! method_exists(Cache::store('model')->getStore(), 'connection')) {
            $this->markTestSkipped('The model cache store is not Redis backed.');
        }
    }
}
