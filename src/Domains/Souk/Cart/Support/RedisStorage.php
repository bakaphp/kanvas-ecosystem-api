<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Support;

use Carbon\Carbon;
use Darryldecode\Cart\CartCollection;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;

class RedisStorage
{
    protected array $data;
    protected string $cartId;
    protected string $key = 'kanvas_souk_cart_';

    public function __construct()
    {
        $this->cartId = $this->key . app(Apps::class)->getId();
        $data = Redis::connection('default')->get($this->cartId);
        $this->data = $data !== null ? $data : [];
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function get(string $key): CartCollection
    {
        return new CartCollection($this->data[$key] ?? []);
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        Redis::connection('default')->set(
            $this->cartId,
            $this->data,
            Carbon::now()->addDays(30)->diffInSeconds(),
        );
    }
}
