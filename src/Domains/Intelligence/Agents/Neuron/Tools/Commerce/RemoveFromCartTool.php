<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\Concerns\ResolvesShopperCart;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Remove From Cart', category: 'commerce')]
class RemoveFromCartTool extends Tool
{
    use ResolvesShopperCart;

    protected string $name = 'remove_from_cart';

    protected ?string $description = 'Takes one line out of the shopper\'s cart and returns the updated cart. Confirm '
        . 'with the shopper which item to remove before calling it. Only works while the shopper chats from '
        . 'the store website.';

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
                description: 'The variant id of the cart line to remove, as shown by view_cart.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $variant_id): array
    {
        $cart = $this->shopperCart();

        if ($cart === null) {
            return $this->cartUnavailable();
        }

        if (! $cart->has($variant_id)) {
            return $this->lineNotInCart($variant_id);
        }

        $cart->remove($variant_id);

        return $this->presentCart($cart);
    }
}
