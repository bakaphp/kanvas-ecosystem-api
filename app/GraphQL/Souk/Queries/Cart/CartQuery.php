<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Cart;

use Kanvas\Inventory\Variants\Models\Variants;

class CartQuery
{
    public function index(): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        $cartItems = array_map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'variant' => Variants::getById($item->id),
                'quantity' => $item->quantity,
                'attributes' => $item->attributes,
            ];
        }, $cart->getContent()->toArray());

        return [
            'id' => 'default',
            'items' => $cartItems, //$cart->getContent()->toArray(),
            'total' => $cart->getTotal(),
        ];
    }
}
