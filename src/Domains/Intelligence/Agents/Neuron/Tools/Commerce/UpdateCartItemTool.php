<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\Concerns\ResolvesShopperCart;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Update Cart Item', category: 'commerce')]
class UpdateCartItemTool extends Tool
{
    use ResolvesShopperCart;

    protected string $name = 'update_cart_item';

    protected ?string $description = 'Sets the quantity of a line already in the shopper\'s cart and returns the updated '
        . 'cart. Confirm the new quantity with the shopper first. To take a line out entirely use '
        . 'remove_from_cart. Only works while the shopper chats from the store website.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'variant_id',
                type: PropertyType::INTEGER,
                description: 'The variant id of the cart line, as shown by view_cart.',
                required: true,
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::INTEGER,
                description: 'The new quantity for that line. Must be at least 1.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $variant_id, int $quantity): array
    {
        $cart = $this->shopperCart();

        if ($cart === null) {
            return $this->cartUnavailable();
        }

        if ($quantity < 1) {
            return $this->invalidArgs('Quantity must be at least 1; use remove_from_cart to drop the line.');
        }

        if (! $cart->has($variant_id)) {
            return $this->lineNotInCart($variant_id);
        }

        $cart->update($variant_id, ['quantity' => ['relative' => false, 'value' => $quantity]]);

        return $this->presentCart($cart);
    }
}
