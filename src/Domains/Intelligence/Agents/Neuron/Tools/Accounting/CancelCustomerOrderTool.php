<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\CancelSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Cancels a sales order that has not shipped. */
#[AgentTool(name: 'Cancel Customer Order', category: 'accounting')]
class CancelCustomerOrderTool extends AbstractCustomerOrderTool
{
    protected string $name = 'cancel_customer_order';

    protected ?string $description = 'Cancels a sales order, recording the reason. An order that already shipped cannot '
        . 'be cancelled. Use it only when the person explicitly asks to cancel the order.';

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
                description: 'The sales order number, e.g. SO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the order is cancelled, in the person\'s words.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, ?string $reason = null): array
    {
        return $this->actOn(
            $order_number,
            'cancelled',
            static fn (SalesOrder $order): SalesOrder => new CancelSalesOrderAction($order, $reason)
                ->execute()
        );
    }
}
