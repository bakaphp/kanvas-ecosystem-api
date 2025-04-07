<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Cart;

use Kanvas\Souk\Cart\Services\CartService;

class CartQuery
{
    public function index(): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        $cartService = new CartService($cart);

        return $cartService->getCart();
    }
}
