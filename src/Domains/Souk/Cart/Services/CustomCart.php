<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Services;

use Joelwmale\Cart\Cart;
use Joelwmale\Cart\CartSession;
use Kanvas\Souk\Cart\Support\RedisStorage;

class CustomCart extends Cart
{
    public function session(string|int $sessionId): self
    {
        $sessionStorage = new RedisStorage((string) $sessionId, $this->config);
        $this->session = new CartSession($sessionStorage, $sessionId, $this->config);

        return $this;
    }
}
