<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Reads the collection follow-up of a shipped customer order, counted from its bill of lading date. */
#[AgentTool(name: 'Get Collection Plan', category: 'accounting')]
class GetCollectionPlanTool extends AbstractCustomerOrderTool
{
    protected string $name = 'get_collection_plan';

    protected ?string $description = 'Gives the collection follow-up of a shipped customer order: each step with its date, '
        . 'counted from the bill of lading date, and who does it (shipping notice, checking the customer registered the '
        . 'invoice, reminders, the due date, calls, escalation and the hold on new orders). Read-only.';

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
        $order = $this->salesOrderByNumber($order_number);

        if ($order === null) {
            return $this->notFound($order_number);
        }

        if ($order->shipment === null) {
            return [
                'found' => true,
                'order_number' => $order->order_number,
                'reason' => 'not_shipped',
                'message' => "Order {$order->order_number} has no bill of lading yet, so there is no collection plan.",
            ];
        }

        return [
            'found' => true,
            'order_number' => $order->order_number,
            'invoice_due_date' => $order->shipment->due_date->toDateString(),
            'collection_plan' => $order->collectionPlan(),
        ];
    }
}
