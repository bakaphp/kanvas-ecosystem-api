<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Jobs\LightHouseCacheCleanUpJob;
use Exception;
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

        try {
            $redis = Redis::connection('graph-cache');

            $cursor = 0;
            $keysFound = false;
            $prefix = config('database.redis.options.prefix', '');
            $iterations = 0;
            $maxIterations = 10000;

            do {
                $result = $redis->scan($cursor, [
                    'match' => $pattern,
                    'count' => 1000,
                ]);

                // Check type first, before any array operations
                if (! is_array($result)) {
                    break;
                }

                // Now safe to check array structure
                if (count($result) < 2) {
                    break;
                }

                $cursor = (int) $result[0];
                $keys = is_array($result[1]) ? $result[1] : [];

                if (! empty($keys)) {
                    $keysFound = true;

                    $keysToDelete = array_map(function ($key) use ($prefix) {
                        return str_replace($prefix, '', $key);
                    }, $keys);

                    $chunks = array_chunk($keysToDelete, 100);
                    foreach ($chunks as $chunk) {
                        if (! empty($chunk)) {
                            $redis->del(...$chunk);
                        }
                    }
                }

                $iterations++;

                if ($iterations >= $maxIterations) {
                    break;
                }
            } while ($cursor != 0);
        } catch (Exception $e) {
            report($e);
            // Silently continue on error
        }

        if (! $keysFound && $withKanvasConfiguration) {
            $this->generateFilesLighthouseCache();

            return;
        }

        if ($withKanvasConfiguration) {
            $this->generateFilesLighthouseCache();
        }
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
