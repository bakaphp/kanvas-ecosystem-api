<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Cart;

class CartQuery
{
    public function index(): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        return [
            'items' => $cart->getContent()->toArray(),
            'total' => $cart->getTotal(),
        ];
    }
}
