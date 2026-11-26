<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Gate 1: confirms a reviewed sales order and hands it to Operations. */
#[AgentTool(name: 'Confirm Customer Order', category: 'accounting')]
class ConfirmCustomerOrderTool extends AbstractCustomerOrderTool
{
    protected string $name = 'confirm_customer_order';

    protected ?string $description = 'Confirms a sales order and hands it to Operations. It is refused unless the order '
        . 'was reviewed against its quote, every difference is clarified in writing, the customer\'s commitment is valid '
        . '(signature checks) and Operations has confirmed the order is feasible. Use it only when the person says the '
        . 'order is confirmed: this is the point from which the customer is promised the order.';

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
                name: 'feasibility_confirmed',
                type: PropertyType::BOOLEAN,
                description: 'True only when Operations has told the person the order is feasible (availability, '
                    . 'container fill, shelf life, regulation). Never assume it.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, bool $feasibility_confirmed): array
    {
        return $this->actOn(
            $order_number,
            'confirmed',
            static fn (SalesOrder $order): SalesOrder => new ConfirmSalesOrderAction($order, $feasibility_confirmed)
                ->execute()
        );
    }
}
