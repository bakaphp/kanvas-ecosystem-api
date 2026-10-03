<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesShopperOrdersForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The shopper's own order history. Signed-in shoppers only: with no People on the session there is
 * nobody to list for, and taking an email from the model instead would let anyone enumerate another
 * customer's orders by typing their address.
 */
#[AgentTool(name: 'List My Orders', category: 'commerce')]
class ListMyOrdersTool extends Tool
{
    use ResolvesShopperOrdersForTool;

    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 25;

    public function __construct(?Session $session = null)
    {
        $this->session = $session;

        parent::__construct(
            name: 'list_my_orders',
            description: 'Lists the signed-in shopper\'s own orders, newest first, with status, fulfillment '
                . 'status, date and total. Use it for "my orders", "my last order", "anything still on the '
                . 'way". Only works when the shopper is signed in — for an anonymous shopper use '
                . 'find_my_order with an order number and email instead.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'only_open',
                type: PropertyType::BOOLEAN,
                description: 'True to return only orders that are still in progress (not completed, '
                    . 'canceled or failed). Defaults to false.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max orders to return. Defaults to 10, max 25.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?bool $only_open = null, ?int $limit = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('order list');
        }

        $shopper = $this->shopper();

        if ($shopper === null) {
            return [
                'count' => 0,
                'orders' => [],
                'reason' => 'not_signed_in',
                'message' => 'The shopper is not signed in, so their order history is not available. Tell '
                    . 'them so, and offer to look up a single order with its order number and email.',
            ];
        }

        $query = $this->shopperOrdersQuery($shopper);

        if ($only_open === true) {
            $query->whereNotIn('status', [OrderStatusEnum::COMPLETED->value, ...OrderStatusEnum::closedValues()]);
        }

        $orders = $query
            ->orderByDesc('id')
            ->limit(max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT)))
            ->get()
            ->map(fn (Order $order): array => $this->presentOrderToShopper($order))
            ->all();

        return [
            'count' => count($orders),
            'orders' => $orders,
        ];
    }
}
