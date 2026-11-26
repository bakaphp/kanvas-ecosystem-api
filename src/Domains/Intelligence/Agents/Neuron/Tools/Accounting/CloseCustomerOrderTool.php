<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\CloseSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Closes a delivered customer order whose invoice is paid. */
#[AgentTool(name: 'Close Customer Order', category: 'accounting')]
class CloseCustomerOrderTool extends AbstractCustomerOrderTool
{
    protected string $name = 'close_customer_order';

    protected ?string $description = 'Closes a delivered customer order once its invoice is paid. It is refused while the '
        . 'invoice is unpaid or the order is not delivered. Use it only when the person asks to close the order.';

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
                description: 'The customer order number, e.g. SO-000012.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number): array
    {
        return $this->actOn(
            $order_number,
            'closed',
            static fn (SalesOrder $order): SalesOrder => new CloseSalesOrderAction($order)->execute()
        );
    }
}
