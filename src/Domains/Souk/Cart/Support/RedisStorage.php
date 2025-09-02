<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Support;

use ArrayAccess;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Cart\Models\Cart;
use Override;

class RedisStorage implements ArrayAccess
{
    protected array $data;
    protected string $cartId;
    protected string $key = 'kanvas_souk_cart_';
    protected string $sessionId;
    protected string $itemsKey;
    protected string $conditionsKey;
    protected bool $enableDatabaseSync;
    protected Apps $app;
    protected ?Cart $cartModel = null;
    protected ?Authenticatable $user = null;

    public function __construct(string $sessionKey, array $config)
    {
        $this->sessionId = $config['storage']['database']['id'] ?? 'id';
        $this->itemsKey = $config['storage']['database']['items'] ?? 'items';
        $this->conditionsKey = $config['storage']['database']['conditions'] ?? 'conditions';
        $this->enableDatabaseSync = $sessionKey === 'cart' ? false : ($config['storage']['database']['sync'] ?? false);
        $this->app = app(Apps::class);
        $this->user = auth()->user();

        $this->cartId = $this->key . $this->app->getId() . '_' . $sessionKey;

        $this->loadData($this->cartId);
    }

    protected function loadData(string $sessionKey): void
    {
        // Try to load from Redis first (fastest)
        $redisData = Redis::connection('cart')->get($this->cartId);

        if ($redisData) {
            $this->data = json_decode($redisData, true);

            // If database sync is enabled, load the cart model
            if ($this->enableDatabaseSync) {
                $this->loadOrCreateCartModel($sessionKey);
            }

            return;
        }

        // If not in Redis, try to load from database (if sync enabled)
        if ($this->enableDatabaseSync) {
            $this->cartModel = Cart::where('session_id', $sessionKey)
                ->where('apps_id', $this->app->getId())
                ->first();

            if ($this->cartModel) {
                // Reconstruct data from database model
                $this->data = [
                    $this->sessionId => $sessionKey,
                    $this->itemsKey => $this->cartModel->metadata['items'] ?? [],
                    $this->conditionsKey => $this->cartModel->metadata['conditions'] ?? [],
                ];

                // Save back to Redis for faster future access
                $this->saveToRedis();

                return;
            }
        }

        // Initialize new cart data
        $this->data = [
            $this->sessionId => $sessionKey,
            $this->itemsKey => [],
            $this->conditionsKey => [],
        ];

        if ($this->enableDatabaseSync) {
            $this->createCartModel($sessionKey);
        }
    }

    protected function loadOrCreateCartModel(string $sessionKey): void
    {
        if ($this->user?->id) {
            // Look for existing pending or abandoned cart from the user
            $existingUserCart = Cart::where('users_id', $this->user->id)
                ->where('apps_id', $this->app->getId())
                ->whereIn('status', ['pending', 'abandoned'])
                ->where('is_deleted', 0)
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($existingUserCart) {
                // If same session_id, update the existing cart with all current data
                if ($existingUserCart->session_id === $sessionKey) {
                    $totalAmount = 0;
                    foreach ($this->data[$this->itemsKey] as $item) {
                        $totalAmount += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    }

                    $existingUserCart->update([
                        'email' => $this->user->email,
                        'users_id' => $this->user->id,
                        'companies_id' => $this->user?->getCurrentCompany()->getId() ?? 1,
                        'amount' => $totalAmount,
                        'currency' => 'usd',
                        'items' => $this->data[$this->itemsKey],
                        'conditions' => $this->data[$this->conditionsKey],
                        'metadata' => [
                            'last_updated' => Carbon::now()->toISOString(),
                        ],
                        'updated_at' => now(),
                    ]);
                    $this->cartModel = $existingUserCart;
                    return;
                } else {
                    // If different session_id, soft delete old cart and create new one
                    $existingUserCart->delete();
                }
            }
        }

        $this->cartModel = Cart::firstOrCreate(
            [
                'session_id' => $sessionKey,
                'apps_id' => $this->app->getId(),
            ],
            [
                'companies_id' => $this->user?->getCurrentCompany()->getId() ?? 1,
                'users_id' => $this->user?->id,
                'email' => $this->user?->email,
                'amount' => 0.00,
                'currency' => 'usd',
                'status' => 'pending',
                'items' => [],
                'conditions' => [],
            ]
        );
    }

    protected function createCartModel(string $sessionKey): void
    {
        $this->cartModel = Cart::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->user?->getCurrentCompany()->getId() ?? 0,
            'users_id' => $this->user?->id,
            'email' => $this->user?->email,
            'session_id' => $sessionKey,
            'amount' => 0.00,
            'currency' => 'usd',
            'status' => 'pending',
            'items' => $this->data[$this->itemsKey],
            'conditions' => $this->data[$this->conditionsKey],
        ]);
    }

    protected function saveToRedis(): bool
    {
        return Redis::connection('cart')->set(
            $this->cartId,
            json_encode($this->data),
            Carbon::now()->addDays(30)->diffInSeconds()
        );
    }

    protected function saveToDatabase(): void
    {
        if (! $this->enableDatabaseSync || ! $this->cartModel) {
            return;
        }

        // Calculate total amount from items
        $totalAmount = 0;
        foreach ($this->data[$this->itemsKey] as $item) {
            $totalAmount += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }

        $this->cartModel->update([
            'amount' => $totalAmount,
            'items' => $this->data[$this->itemsKey],
            'conditions' => $this->data[$this->conditionsKey],
            'metadata' => [
                'last_updated' => Carbon::now()->toISOString(),
            ],
        ]);
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
        $this->data[$this->sessionId] = $attributes[$this->sessionId];

        return $this;
    }

    public function save(): bool
    {
        $redisResult = $this->saveToRedis();

        if ($this->enableDatabaseSync) {
            $this->saveToDatabase();
        }

        return $redisResult;
    }

    public function delete(): int
    {
        $redisResult = Redis::connection('cart')->del($this->cartId);

        if ($this->enableDatabaseSync && $this->cartModel) {
            $this->cartModel->delete();
        }

        return $redisResult;
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

    public function getCartModel(): ?Cart
    {
        return $this->cartModel;
    }

    public function syncToDatabase(): void
    {
        if ($this->enableDatabaseSync) {
            $this->saveToDatabase();
        }
    }

    public function forceLoadFromDatabase(string $sessionKey): bool
    {
        if (! $this->enableDatabaseSync) {
            return false;
        }

        $cart = Cart::where('session_id', $sessionKey)
            ->where('apps_id', $this->app->getId())
            ->first();

        if (! $cart) {
            return false;
        }

        $this->cartModel = $cart;
        $this->data = [
            $this->sessionId => $sessionKey,
            $this->itemsKey => $cart->metadata['items'] ?? [],
            $this->conditionsKey => $cart->metadata['conditions'] ?? [],
        ];

        // Update Redis with database data
        $this->saveToRedis();

        return true;
    }
}
