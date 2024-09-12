<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Jobs\LightHouseCacheCleanUpJob;
use Illuminate\Support\Facades\Redis;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;

trait HasLightHouseCache
{
    abstract public function getGraphTypeName(): string;

    public function clearLightHouseCache(): void
    {
        $key = $this->generateLighthouseCacheKey() . '*';
        $redis = Redis::connection('graph-cache');
        $keys = $redis->keys($key);
        if (empty($keys)) {
            $this->generateCustomFieldsLighthouseCache();
            $this->generateFilesLighthouseCache();

            return;
        }

        foreach ($keys as $key) {
            $redis->del(str_replace(config('database.redis.options.prefix'), '', $key));
        }

        $this->generateCustomFieldsLighthouseCache();
        $this->generateFilesLighthouseCache();
    }

    public function clearLightHouseCacheJob(): void
    {
        LightHouseCacheCleanUpJob::dispatch($this);
    }

    public function generateRelationshipLighthouseCache(string $relationship, int $items = 25): void
    {
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;
        $key = $this->generateLighthouseCacheKey() . $separator . $relationship . $separator . 'first' . $separator . $items;
        $redis = Redis::connection('graph-cache');
        $result = $this->getRelationshipQueryBuilder($relationship)->paginate($items);
        $redis->set($key, $result);
    }

    public function generateCustomFieldsLighthouseCache(int $items = 25): void
    {
        if (method_exists($this, 'reCacheCustomFields')) {
            /**
             * @todo maybe not needed
             */
            $this->reCacheCustomFields($items);
        }

        $this->generateRelationshipLighthouseCache('custom_fields', $items);
    }

    public function generateFilesLighthouseCache(int $items = 25): void
    {
        $this->generateRelationshipLighthouseCache('files', $items);
    }

    protected function generateLighthouseCacheKey(): string
    {
        $graphTypeName = $this->getGraphTypeName();
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;

        return CacheKeyAndTagsGenerator::PREFIX . $separator . $graphTypeName . $separator . $this->getId();
    }

    protected function getRelationshipQueryBuilder(string $relationship)
    {
        return match ($relationship) {
            'custom_fields' => $this->getCustomFieldsQueryBuilder(),
            'files' => $this->getFilesQueryBuilder(),
            default => $this->$relationship(),
        };
    }
}
