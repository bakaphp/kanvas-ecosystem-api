<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DescribesPurchaseOrderLines;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\UpdatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Edits a draft purchase order of the company. */
#[AgentTool(name: 'Update Purchase Order', category: 'accounting')]
class UpdatePurchaseOrderTool extends Tool
{
    use DescribesPurchaseOrderLines;
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'update_purchase_order';

    protected ?string $description = 'Edits a draft purchase order: its lines, expected date, notes, vendor or currency. '
        . 'Only what is sent changes, and sending lines replaces all of them. An order that is no longer a draft, or that '
        . 'comes from an external system, cannot be edited. Use it only when the person asks to change the order.';

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
                description: 'The purchase order number to edit, e.g. PO-000012.',
                required: true,
            ),
            $this->purchaseOrderLinesProperty(
                'The full new list of lines. It replaces the current lines, so include every item that stays.',
                required: false
            ),
            new ToolProperty(
                name: 'expected_date',
                type: PropertyType::STRING,
                description: 'New expected delivery date, as YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'New notes for the order.',
                required: false,
            ),
            new ToolProperty(
                name: 'vendor_organization_id',
                type: PropertyType::NUMBER,
                description: 'Move the order to another vendor (find_vendor returns the id).',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'New three-letter currency code.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|null $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        ?array $lines = null,
        ?string $expected_date = null,
        ?string $notes = null,
        ?int $vendor_organization_id = null,
        ?string $currency = null,
    ): array {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['updated' => false] + $this->purchaseOrderNotFound($order_number);
        }

        $changes = array_filter([
            'lines' => $lines,
            'expected_date' => $expected_date,
            'notes' => $notes,
            'vendor_id' => $vendor_organization_id,
            'currency' => $currency,
        ], static fn (mixed $change): bool => $change !== null);

        try {
            $updated = new UpdatePurchaseOrderAction(
                $order,
                PurchaseOrderData::forUpdate(
                    $order,
                    $this->app,
                    $this->user,
                    $this->company,
                    $changes
                ),
            )->execute();
        } catch (Throwable $e) {
            return [
                'updated' => false,
                'order_number' => $order->order_number,
                'reason' => 'update_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'updated' => true,
            'order_number' => $updated->order_number,
            'document_status' => $updated->document_status->value,
            'order_total' => (float) $updated->order_total,
            'line_count' => $updated->lines->count(),
        ];
    }
}
