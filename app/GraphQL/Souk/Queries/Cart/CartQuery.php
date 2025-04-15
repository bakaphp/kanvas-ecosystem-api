<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Cart;

use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Enums\AppEnums;
class CartQuery
{
    public function index(): array
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        $cartService = new CartService($cart);

        return $cartService->getCart();
    }
}
