<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Redis;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;

class EleadDebounce
{
    private const string PREFIX = 'elead:debounce';

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function shouldSkip(string $activity, string $entityId, ?int $ttl = null): bool
    {
        $key = $this->buildKey($activity, $entityId);

        $isFirstCall = Redis::set($key, (string) time(), 'EX', $ttl ?? $this->getDebounceSeconds(), 'NX');

        return ! $isFirstCall;
    }

    public function clear(string $activity, string $entityId): void
    {
        Redis::del($this->buildKey($activity, $entityId));
    }

    protected function buildKey(string $activity, string $entityId): string
    {
        return self::PREFIX . ':' . (string) $this->company->getId() . ':' . $activity . ':' . $entityId;
    }

    protected function getDebounceSeconds(): int
    {
        return (int) ($this->app->get(ConfigurationEnum::ELEAD_DEBOUNCE_SECONDS->value) ?? 30);
    }
}
