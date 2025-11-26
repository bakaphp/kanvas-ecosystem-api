<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Jobs\LightHouseCacheCleanUpJob;
use Illuminate\Support\Facades\Redis;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;

trait HasLightHouseCache
{
    abstract public function getGraphTypeName(): string;

    public function clearLightHouseCache(
        bool $withKanvasConfiguration = true,
        bool $cleanGlobalKey = false
    ): void {
        $pattern = $this->generateLighthouseCacheKey(globalModelKey: $cleanGlobalKey) . '*';
        $redis = Redis::connection('graph-cache');

        // SAFE key lookup using SCAN
        $keys = $this->scanKeys($redis, $pattern);

        // If no keys exist, regenerate basic cache
        if (empty($keys) && $withKanvasConfiguration) {
            $this->generateFilesLighthouseCache();

            return;
        }

        // Batch delete keys using PIPELINE
        if (! empty($keys)) {
            $redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $pipe->del(
                        str_replace(
                            config('database.redis.options.prefix'),
                            '',
                            $key
                        )
                    );
                }
            });
        }

        // Rebuild cache if needed
        if ($withKanvasConfiguration) {
            $this->generateFilesLighthouseCache();
        }
    }

    protected function scanKeys($redis, string $pattern): array
    {
        $cursor = 0;
        $keys = [];

        do {
            [$cursor, $found] = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 1000);
            if (! empty($found)) {
                $keys = array_merge($keys, $found);
            }
        } while ($cursor != 0);

        return $keys;
    }

    public function clearLightHouseCacheJob(): void
    {
        if (! app()->runningInConsole()) {
            LightHouseCacheCleanUpJob::dispatch($this);
        } else {
            $this->clearLightHouseCache();
        }
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
            $this->reCacheCustomFields($items);
        }

        $this->generateRelationshipLighthouseCache('custom_fields', $items);
    }

    public function generateFilesLighthouseCache(int $items = 25): void
    {
        $this->generateRelationshipLighthouseCache('files', $items);
    }

    protected function generateLighthouseCacheKey(bool $globalModelKey = false): string
    {
        $graphTypeName = $this->getGraphTypeName();
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;

        $key = CacheKeyAndTagsGenerator::PREFIX . $separator . $graphTypeName;

        return $globalModelKey ? $key : $key . $separator . $this->getId();
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
