<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Redis;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;

class EleadCache
{
    private const string PREFIX = 'elead';

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function getEntityTtl(): int
    {
        return (int) ($this->app->get(ConfigurationEnum::ELEAD_CACHE_TTL->value) ?? 300);
    }

    public function getReferenceTtl(): int
    {
        return (int) ($this->app->get(ConfigurationEnum::ELEAD_REF_CACHE_TTL->value) ?? 86400);
    }

    protected function buildKey(string $entity, string $id): string
    {
        return self::PREFIX . ':' . (string) $this->company->getId() . ':' . $entity . ':' . $id;
    }

    public function get(string $entity, string $id): ?array
    {
        $cached = Redis::get($this->buildKey($entity, $id));

        if (! is_string($cached)) {
            return null;
        }

        return json_decode($cached, true);
    }

    public function set(string $entity, string $id, array $data, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->getEntityTtl();
        Redis::setex($this->buildKey($entity, $id), $ttl, json_encode($data));
    }

    public function setReference(string $entity, string $id, array $data): void
    {
        $this->set($entity, $id, $data, $this->getReferenceTtl());
    }

    public function invalidate(string $entity, string $id): void
    {
        Redis::del($this->buildKey($entity, $id));
    }

    public function flushAll(): int
    {
        $pattern = self::PREFIX . ':' . (string) $this->company->getId() . ':*';
        $cursor = '0';
        $deleted = 0;

        do {
            $result = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', 100);

            if (! is_array($result)) {
                break;
            }

            $cursor = (string) $result[0];
            $keys = is_array($result[1]) ? $result[1] : [];

            if (! empty($keys)) {
                $deleted += count($keys);
                Redis::del(...$keys);
            }
        } while ($cursor !== '0');

        return $deleted;
    }
}
