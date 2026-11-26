<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\CancelPurchaseOrderAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Cancels a purchase order of the company that has not received goods yet. */
#[AgentTool(name: 'Cancel Purchase Order', category: 'accounting')]
class CancelPurchaseOrderTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'cancel_purchase_order';

    protected ?string $description = 'Cancels a purchase order of the company, recording the reason. An order that already '
        . 'received goods, or that comes from an external system, cannot be cancelled. Use it only when the person '
        . 'explicitly asks to cancel the order.';

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
                description: 'The purchase order number to cancel, e.g. PO-000012.',
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
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['cancelled' => false] + $this->purchaseOrderNotFound($order_number);
        }

        try {
            $cancelled = new CancelPurchaseOrderAction($order, $reason)->execute();
        } catch (Throwable $e) {
            return [
                'cancelled' => false,
                'order_number' => $order->order_number,
                'reason' => 'cancel_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'cancelled' => true,
            'order_number' => $cancelled->order_number,
            'document_status' => $cancelled->document_status->value,
            'cancelled_at' => $cancelled->cancelled_at?->toIso8601String(),
        ];
    }
}
