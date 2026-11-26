<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Renders a purchase order as a PDF and attaches it to the order. */
#[AgentTool(name: 'Generate Purchase Order PDF', category: 'accounting')]
class GeneratePurchaseOrderPdfTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'generate_purchase_order_pdf';

    protected ?string $description = 'Renders a purchase order as a PDF (company, vendor, lines, total and notes) and attaches '
        . 'it to the order. It sends nothing. Returns a filesystem_id: call get_file_link with it to hand the person a '
        . 'link, never the id. Use it when the person wants a copy of the order.';

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
                description: 'The purchase order number, e.g. PO-000012.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number): array
    {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['generated' => false] + $this->purchaseOrderNotFound($order_number);
        }

        try {
            $file = new PurchaseOrderPdfService($order)->generate($this->user);
        } catch (Throwable $e) {
            return [
                'generated' => false,
                'order_number' => $order->order_number,
                'reason' => 'pdf_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'generated' => true,
            'order_number' => $order->order_number,
            'filesystem_id' => $file->getId(),
            'file_name' => $file->name,
        ];
    }
}
