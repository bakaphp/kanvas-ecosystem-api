<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Actions\CheckShippingDocumentsAction;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Cross-checks the order, the commercial invoice, the packing list and the draft bill of lading before the BL. */
#[AgentTool(name: 'Check Shipping Documents', category: 'accounting')]
class CheckShippingDocumentsTool extends AbstractCustomerOrderTool
{
    protected string $name = 'check_shipping_documents';

    protected ?string $description = 'Before the bill of lading is issued, cross-checks the four documents of an accepted '
        . 'customer order: the order (PO or proforma), the commercial invoice, the packing list and the draft bill of '
        . 'lading. It lists every difference in total, products and quantities. The invoice should follow what was really '
        . 'loaded, so a difference with the order has to be explained; every difference must be clarified (resolve_po_difference with '
        . 'source documents) before record_shipment works. The invoice chosen here is the one that falls due from the '
        . 'bill of lading date. It may still be a draft while it is checked and corrected, and has to be issued before '
        . 'record_shipment.';

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
                description: 'The customer order number, e.g. SO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The number of the commercial invoice for this shipment (find_invoice shows it).',
                required: true,
            ),
            new ArrayProperty(
                name: 'packing_list',
                description: 'The packing list lines, in the same order as the invoice lines.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'A packing list line.',
                    properties: [
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'Quantity really loaded for this line.',
                            required: true,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'bl_total_quantity',
                type: PropertyType::NUMBER,
                description: 'The total quantity declared on the draft bill of lading.',
                required: true,
            ),
        ];
    }

    /**
     * @param array<int, array{quantity: float|int}> $packing_list
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        string $invoice_number,
        array $packing_list,
        float $bl_total_quantity,
    ): array {
        $invoice = $this->findTenantRecordOrNotFound(
            Invoice::class,
            'invoice_number',
            $invoice_number,
            'invoice'
        );

        if (! $invoice instanceof Invoice) {
            return [
                'checked' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice numbered {$invoice_number} for this company.",
            ];
        }

        return $this->actOn(
            $order_number,
            'checked',
            static fn (SalesOrder $order): SalesOrder => new CheckShippingDocumentsAction(
                $order,
                $invoice,
                array_values($packing_list),
                ['total_quantity' => $bl_total_quantity]
            )->execute()
        );
    }
}
