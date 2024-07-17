<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Support\Facades\Redis;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;

trait HasLightHouseCache
{
    public abstract function getGraphTypeName(): string;

    public function clearLightHouseCache(): void
    {
        $graphTypeName = $this->getGraphTypeName();
        
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;
        $key = CacheKeyAndTagsGenerator::PREFIX . $separator . $graphTypeName . $separator . $this->getId() . '*';
        $redis = Redis::connection('graph-cache');
        $keys = $redis->keys($key);
        if (empty($keys)) {
            return;
        }

        foreach ($keys as $key) {
            $redis->del(str_replace(config('database.redis.options.prefix'), '', $key));
        }
    }
}
