<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Redis;
use Kanvas\Inventory\Products\Models\Products;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;
use Tests\TestCase;

class KanvasLighthouseRedisCacheCommandTest extends TestCase
{
    public function testIdOptionRegeneratesOnlyThatEntity(): void
    {
        $target = Products::factory()->create();
        $other = Products::factory()->create(['apps_id' => $target->apps_id]);
        $redis = Redis::connection('graph-cache');

        $redis->hSet($this->hashKey($target), 'files:first:25', 'stale-payload');
        $redis->hSet($this->hashKey($other), 'files:first:25', 'stale-payload');

        $this->artisan('kanvas:lighthouse-redis-cache', [
            'class' => Products::class,
            'app_id' => $target->apps_id,
            '--id' => [$target->getId()],
        ])->assertSuccessful();

        $this->assertNotSame('stale-payload', $redis->hGet($this->hashKey($target), 'files:first:25'));
        $this->assertSame('stale-payload', $redis->hGet($this->hashKey($other), 'files:first:25'));
    }

    private function hashKey(Products $product): string
    {
        return CacheKeyAndTagsGenerator::PREFIX . ':' . $product->getGraphTypeName() . ':' . $product->getId();
    }
}
