<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderLine;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Reads one sales order: where it stands, its lines, its differences and its dates. */
#[AgentTool(name: 'Find Customer Order', category: 'accounting')]
class FindCustomerOrderTool extends AbstractCustomerOrderTool
{
    protected string $name = 'find_customer_order';

    protected ?string $description = 'Looks up one sales order by its SO number and returns where it stands (status, '
        . 'what has to happen next), the customer PO it came from, its lines, the differences the review found and the '
        . 'dates Operations committed to.';

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

        return ['found' => true] + $this->summary($order) + [
            'requested_date' => $order->requested_date?->toDateString(),
            'incoterm' => $order->incoterm,
            'payment_terms_days' => $order->payment_terms_days,
            'return_reason' => $order->return_reason,
            'differences' => $order->differences(),
            'lines' => $order->lines()->orderBy('line_number')->get()->map(static fn (SalesOrderLine $line): array => [
                'line_number' => $line->line_number,
                'sku' => $line->sku,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all(),
        ];
    }
}
