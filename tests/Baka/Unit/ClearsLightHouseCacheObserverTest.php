<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Observers\ClearsLightHouseCacheObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Scribe\Quotes\Models\Quote;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * The shared observer is the only thing standing between a write and a stale `@cacheRedis` read,
 * and until this existed nothing exercised the hook itself — the other cache tests either call
 * clearLightHouseCache() directly or mock it, so the wiring that actually invokes it was untested.
 */
class ClearsLightHouseCacheObserverTest extends TestCase
{
    public function testWritingARecordDropsItsWarmedCacheEntry(): void
    {
        $category = $this->makeCategory();
        $redis = Redis::connection('graph-cache');
        $hashKey = CacheKeyAndTagsGenerator::PREFIX
            . CacheKeyAndTagsGenerator::SEPARATOR . $category->getGraphTypeName()
            . CacheKeyAndTagsGenerator::SEPARATOR . $category->getId();

        $redis->hSet($hashKey, 'files:first:25', 'warmed-payload');
        $this->assertSame(1, (int) $redis->exists($hashKey), 'precondition: the entry is warm');

        $category->name = 'renamed-' . uniqid();
        $category->save();

        $this->assertSame(
            0,
            (int) $redis->exists($hashKey),
            'A write must drop the entry, or the next read serves the pre-write payload.',
        );
    }

    /**
     * `saved` fires after the row is committed; `updating` fires before it. Clearing on the early
     * hook leaves a window where a concurrent read re-warms from the uncommitted row and the stale
     * entry survives until the next write, so the hook name is load-bearing, not a style choice.
     */
    public function testTheObserverClearsOnTheCommittedHookNotThePreWriteOne(): void
    {
        $observer = new ClearsLightHouseCacheObserver();

        $this->assertTrue(method_exists($observer, 'saved'));
        $this->assertFalse(
            method_exists($observer, 'updating'),
            'updating() fires pre-commit — reintroducing it reopens the stale-refill race.',
        );
    }

    /** Losing the attribute silently disables invalidation, which is invisible until data goes stale. */
    #[DataProvider('modelsOnTheSharedObserver')]
    public function testModelsDeclareTheSharedObserver(string $modelClass): void
    {
        $observers = array_merge(
            ...array_map(
                static fn ($attribute): array => (array) $attribute->newInstance()->classes,
                new ReflectionClass($modelClass)->getAttributes(ObservedBy::class),
            )
        );

        $this->assertContains(ClearsLightHouseCacheObserver::class, $observers);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function modelsOnTheSharedObserver(): array
    {
        return [
            'inventory category' => [Categories::class],
            'scribe quote' => [Quote::class],
        ];
    }

    private function makeCategory(): Categories
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $name = 'cache-observer-' . uniqid();

        return Categories::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'slug' => $name,
        ]);
    }
}
