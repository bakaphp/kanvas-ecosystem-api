<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\Concerns\ResolvesShopperCart;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Same path as the storefront's addToCart mutation, so pricing, currency and channel resolution
 * cannot drift between the page and the chat.
 */
#[AgentTool(name: 'Add To Cart', category: 'commerce')]
class AddToCartTool extends Tool
{
    use ResolvesShopperCart;

    protected string $name = 'add_to_cart';

    protected ?string $description = 'Adds a product variant to the shopper\'s cart on the store and returns the updated '
        . 'cart. Only call it after the shopper clearly asked for that exact item and quantity and you have '
        . 'confirmed it with them. Use the variant id from inventory_search, variant_search or '
        . 'variant_detail; never guess one. Adding a variant already in the cart increases its quantity. '
        . 'Only works while the shopper chats from the store website.';

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
                description: 'The id of the variant to add, taken from a product tool result.',
                required: true,
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::INTEGER,
                description: 'How many units to add. Defaults to 1.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $variant_id, ?int $quantity = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('cart');
        }

        $cart = $this->shopperCart();

        if ($cart === null) {
            return $this->cartUnavailable();
        }

        $quantity ??= 1;

        if ($quantity < 1) {
            return $this->invalidArgs('Quantity must be at least 1.');
        }

        try {
            new AddToCartAction($this->app, $this->company, $this->contextUser())->execute($cart, [[
                'variant_id' => $variant_id,
                'quantity' => $quantity,
            ]]);
        } catch (Throwable $e) {
            return $this->failed("Could not add variant #{$variant_id} to the cart: {$e->getMessage()}");
        }

        return $this->presentCart($cart);
    }
}
