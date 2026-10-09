<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\Concerns\ResolvesShopperCart;
use NeuronAI\Tools\Tool;

#[AgentTool(name: 'View Cart', category: 'commerce')]
class ViewCartTool extends Tool
{
    use ResolvesShopperCart;

    protected string $name = 'view_cart';

    protected ?string $description = 'Shows the shopper\'s current cart on the store: each line with quantity, unit '
        . 'price and line total, any discounts, fees or shipping applied, and the subtotal and total. Call it '
        . 'before changing the cart and again after, so you can tell the shopper the new total. Only works '
        . 'while the shopper chats from the store website; elsewhere it says the cart is unavailable.';

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $cart = $this->shopperCart();

        if ($cart === null) {
            return $this->cartUnavailable();
        }

        return $this->presentCart($cart);
    }
}
