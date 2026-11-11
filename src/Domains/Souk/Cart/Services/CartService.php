<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Services;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
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
        $discounts = array_values(array_filter(array_map(function ($discount) use (&$totalDiscount) {
            if ($discount['type'] === 'shipping' || $discount['type'] === TaxConditionEnum::TYPE->value) {
                return null;
            }
            $totalDiscount += $this->cart->getCalculatedValueForCondition($discount['name']);

            return [
                'code' => $discount['name'],
                'amount' => $discount['value'],
                'total' => $this->cart->getCalculatedValueForCondition($discount['name']),
            ];
        }, $this->cart->getConditions(true))));

        $shipping = $this->cart->getCondition('Shipping');
        $taxCondition = $this->cart->getCondition(TaxConditionEnum::NAME->value);
        $taxAttributes = $taxCondition?->getAttributes() ?? [];

        $totalTax = 0.0;
        if (isset($taxAttributes['components']) && is_array($taxAttributes['components'])) {
            foreach ($taxAttributes['components'] as $component) {
                $totalTax += (float) ($component['amount'] ?? 0);
            }
        }

        $tax = null;
        if ($taxCondition !== null) {
            $tax = [
                'status' => $taxAttributes['status'] ?? 'unknown',
                'provider' => $taxAttributes['provider'] ?? null,
                'amount' => $taxAttributes['amount'] ?? $totalTax,
                'rate' => null,
                'mode' => $taxAttributes['mode'] ?? 'exclusive',
                'components' => $taxAttributes['components'] ?? [],
            ];
        }

        $response = [
            'id' => 'default',
            'items' => $cartItems,
            'discounts' => $discounts,
            'total_discount' => $totalDiscount,
            'subtotal' => $this->cart->getSubTotalWithoutConditions(false),
            'total' => $this->cart->getTotal(),
            'total_tax' => $totalTax,
        ];

        if ($shipping) {
            $response['shipping'] = [
                'name' => $shipping->getName(),
                'value' => $shipping->getValue(),
                'attributes' => $shipping->getAttributes(),
            ];
        }

        if ($tax !== null) {
            $response['tax'] = $tax;
        }

        return $response;
    }
}
