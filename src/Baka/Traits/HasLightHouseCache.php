<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Support\Facades\Redis;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;

trait HasLightHouseCache
{
    abstract public function getGraphTypeName(): string;

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

        $this->generateCustomFieldsLighthouseCache();
        $this->generateFilesLighthouseCache();
    }

    public function generateRelationshipLighthouseCache(string $relationship, int $items = 25): void
    {
        $graphTypeName = $this->getGraphTypeName();
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;
        $key = CacheKeyAndTagsGenerator::PREFIX . $separator . $graphTypeName . $separator . $this->getId() . ':' . $relationship . ':first:' . $items;
        $redis = Redis::connection('graph-cache');
        $result = $this->customFields()->paginate($items);
        $redis->set($key, $result);
    }

    public function generateCustomFieldsLighthouseCache(int $items = 25): void
    {
        $this->generateRelationshipLighthouseCache('custom_fields', $items);
    }

    public function generateFilesLighthouseCache(int $items = 25): void
    {
        $this->generateRelationshipLighthouseCache('files', $items);
    }
}
