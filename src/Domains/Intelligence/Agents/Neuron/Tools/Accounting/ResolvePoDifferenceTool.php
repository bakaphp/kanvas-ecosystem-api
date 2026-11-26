<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Actions\ResolveSalesOrderDifferenceAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Records how one difference found in a sales order's review was clarified with the customer. */
#[AgentTool(name: 'Resolve PO Difference', category: 'accounting')]
class ResolvePoDifferenceTool extends AbstractCustomerOrderTool
{
    protected string $name = 'resolve_po_difference';

    protected ?string $description = 'Records, in the person\'s words, how one difference found by review_customer_po '
        . 'was clarified with the customer (for example "customer confirmed FOB by email on 20 Nov"). Use the key the '
        . 'review returned. Only record a clarification the person actually reports: never invent one.';

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
                name: 'key',
                type: PropertyType::STRING,
                description: 'The key of the difference, as review_customer_po or the order\'s open_differences lists it.',
                required: true,
            ),
            new ToolProperty(
                name: 'resolution',
                type: PropertyType::STRING,
                description: 'How it was clarified with the customer.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, string $key, string $resolution): array
    {
        return $this->actOn(
            $order_number,
            'resolved',
            static fn (SalesOrder $order): SalesOrder => new ResolveSalesOrderDifferenceAction($order, $key, $resolution)
                ->execute()
        );
    }
}
