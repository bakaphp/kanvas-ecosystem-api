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
        $redis = Redis::connection('graph-cache');
        $hashKey = $this->generateLighthouseHashKey($cleanGlobalKey);

        $redis->del($hashKey);

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
        $redis = Redis::connection('graph-cache');
        $hashKey = $this->generateLighthouseHashKey();
        $separator = CacheKeyAndTagsGenerator::SEPARATOR;

        // Field key matches what directive expects
        $fieldKey = $relationship . $separator . 'first' . $separator . $items;

        $result = $this->getRelationshipQueryBuilder($relationship)->paginate($items);

        $redis->hSet($hashKey, $fieldKey, $result);
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

    /**
     * Generate the Redis hash key (matches directive's extractHashParts).
     */
    protected function generateLighthouseHashKey(bool $globalModelKey = false): string
    {
        $graphTypeName = $this->getGraphTypeName();

        if ($globalModelKey) {
            return CacheKeyAndTagsGenerator::PREFIX . ":{$graphTypeName}";
        }

        return CacheKeyAndTagsGenerator::PREFIX . ":{$graphTypeName}:{$this->getId()}";
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
