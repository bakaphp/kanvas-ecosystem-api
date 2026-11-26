<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\ReceivePurchaseOrderAction;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Records goods received against a sent purchase order. */
#[AgentTool(name: 'Receive Purchase Order', category: 'accounting')]
class ReceivePurchaseOrderTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'receive_purchase_order';

    protected ?string $description = 'Records goods received against a sent purchase order: how much of each order line '
        . 'arrived. The order becomes partially received until every line is covered, then received. Receiving more than '
        . 'is still open on a line is refused. Use it only when the person says goods arrived and tells you what: never '
        . 'assume a delivery is complete.';

    /**
     * @return array<int, ToolProperty|ArrayProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The purchase order number, e.g. PO-000012.',
                required: true,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'What arrived, one entry per order line.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'The quantity received of one order line.',
                    properties: [
                        new ToolProperty(
                            name: 'line_number',
                            type: PropertyType::INTEGER,
                            description: 'The order\'s line number (find_purchase_order lists them).',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'How much of that line arrived. Above zero.',
                            required: true,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'received_on',
                type: PropertyType::STRING,
                description: 'The day the goods arrived, as YYYY-MM-DD. Today when left out.',
                required: false,
            ),
            new ToolProperty(
                name: 'reference',
                type: PropertyType::STRING,
                description: 'The vendor\'s delivery note or packing slip number.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Anything about the delivery, such as damage.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<array{line_number: int, quantity: float|int}> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        array $lines,
        ?string $received_on = null,
        ?string $reference = null,
        ?string $notes = null,
    ): array {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['received' => false] + $this->purchaseOrderNotFound($order_number);
        }

        try {
            $receipt = new ReceivePurchaseOrderAction(
                order: $order,
                user: $this->user,
                receivedOn: $received_on === null ? Carbon::today() : Carbon::parse($received_on),
                lines: $lines,
                reference: $reference,
                notes: $notes,
            )->execute();
        } catch (Throwable $e) {
            return [
                'received' => false,
                'order_number' => $order->order_number,
                'reason' => 'receive_failed',
                'message' => $e->getMessage(),
            ];
        }

        $order->refresh();

        return [
            'received' => true,
            'order_number' => $order->order_number,
            'receipt_number' => $receipt->receipt_number,
            'document_status' => $order->document_status->value,
            'open_lines' => $order->lines()->where('open_qty', '>', 0)->count(),
        ];
    }
}
