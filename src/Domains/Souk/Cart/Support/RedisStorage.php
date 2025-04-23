<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Support;

use ArrayAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Override;

class RedisStorage implements ArrayAccess
{
    protected array $data;
    protected string $cartId;
    protected string $key = 'kanvas_souk_cart_';
    protected string $sessionId;
    protected string $itemsKey;
    protected string $conditionsKey;

    public function __construct(string $sessionKey, array $config)
    {
        $this->sessionId = $config['storage']['database']['id'] ?? 'id';
        $this->itemsKey = $config['storage']['database']['items'] ?? 'items';
        $this->conditionsKey = $config['storage']['database']['conditions'] ?? 'conditions';

        $this->cartId = $this->key.app(Apps::class)->getId().'_'.$sessionKey;

        $redisData = Redis::connection('cart')->get($this->cartId);

        // Initialize data structure if it doesn't exist
        if (!$redisData) {
            $this->data = [
                $this->sessionId     => $sessionKey,
                $this->itemsKey      => [],
                $this->conditionsKey => [],
            ];
        } else {
            $this->data = json_decode($redisData, true);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function firstOrNew(array $attributes): self
    {
        // This mimics Eloquent's firstOrNew
        $this->data[$this->sessionId] = $attributes[$this->sessionId];

        return $this;
    }

    public function save(): bool
    {
        return Redis::connection('cart')->set(
            $this->cartId,
            json_encode($this->data),
            Carbon::now()->addDays(30)->diffInSeconds()
        );
    }

    public function delete(): int
    {
        return Redis::connection('cart')->del($this->cartId);
    }

    public function __get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, mixed $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function getSessionModel(): self
    {
        return $this;
    }
}
