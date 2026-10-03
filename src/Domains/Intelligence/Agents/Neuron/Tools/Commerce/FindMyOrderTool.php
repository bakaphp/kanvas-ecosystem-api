<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesShopperOrdersForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * "Where is my order" for the shopper in the conversation. An identified shopper is matched on their
 * own People; an anonymous one must supply the email on the order as well, and a miss on either
 * field reads the same so the tool cannot be used to probe which order numbers exist.
 */
#[AgentTool(name: 'Find My Order', category: 'commerce')]
class FindMyOrderTool extends Tool
{
    use ResolvesShopperOrdersForTool;

    public function __construct(?Session $session = null)
    {
        $this->session = $session;

        parent::__construct(
            name: 'find_my_order',
            description: 'Looks up ONE of the shopper\'s own orders by order number: status, payment and '
                . 'fulfillment status, shipped date, total and line items. Use it for "where is my order", '
                . '"did my order ship", "what did I order". A signed-in shopper only needs the order number. '
                . 'An anonymous shopper must also give the email used on the order — ask for it, never guess '
                . 'it. It only ever returns the shopper\'s own orders.',
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
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The order number the shopper gave you.',
                required: true,
            ),
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'The email used on the order. Required when the shopper is not signed in; '
                    . 'ignored when they are.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, ?string $email = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('order lookup');
        }

        $orderNumber = trim($order_number);
        if ($orderNumber === '') {
            return [
                'found' => false,
                'message' => 'Ask the shopper for their order number, then retry.',
            ];
        }

        $shopper = $this->shopper();

        if ($shopper !== null) {
            $query = $this->shopperOrdersQuery($shopper);
        } else {
            $email = Str::trimToNull($email);

            if ($email === null) {
                return [
                    'found' => false,
                    'reason' => 'email_required',
                    'message' => 'The shopper is not signed in. Ask for the email used on the order, then '
                        . 'retry with both the order number and the email.',
                ];
            }

            $query = $this->ordersQuery()->whereRaw('LOWER(user_email) = ?', [mb_strtolower($email)]);
        }

        /** @var Order|null $order */
        $order = $query->where('order_number', $orderNumber)->first();

        if ($order === null) {
            return [
                'found' => false,
                'message' => 'No order matches those details for this shopper. Ask them to double-check '
                    . 'the order number' . ($shopper === null ? ' and the email' : '') . '. Do not guess.',
            ];
        }

        return ['found' => true, ...$this->presentOrderToShopper($order, withItems: true)];
    }
}
