<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Services;

use Kanvas\Inventory\Variants\Models\Variants;
use Wearepixel\Cart\Cart;

class CartService
{
    public function __construct(
        protected Cart $cart
    ) {
    }

    public function getCart(): array
    {
        $cartItems = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'price' => $item['price'],
                'variant' => Variants::getById($item['id']),
                'quantity' => $item['quantity'],
                'attributes' => $item['attributes'],
            ];
        }, $this->cart->getContent()->toArray());

        $totalDiscount = 0;
        $discounts = array_map(function ($discount) use (&$totalDiscount) {
            $totalDiscount += $this->cart->getCalculatedValueForCondition($discount['name']);

            return [
                'code' => $discount['name'],
                'amount' => $discount['value'],
                'total' => $this->cart->getCalculatedValueForCondition($discount['name']),
            ];
        }, $this->cart->getConditions(true));

        /**
         * @todo move to DTO
         */
        return [
            'id' => 'default',
            'items' => $cartItems, //$this->cart->getContent()->toArray(),
            'discounts' => $discounts,
            'total_discount' => $totalDiscount,
            'total' => $this->cart->getTotal(),
        ];
    }
}
