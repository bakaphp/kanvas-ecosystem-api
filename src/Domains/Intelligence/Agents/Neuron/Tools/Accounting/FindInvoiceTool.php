<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE invoice (receivable — money a customer owes US) by its number: status, amounts
 * (total / paid / balance), dates, and line items. The AR mirror of find_bill. Reads synced data;
 * reports found=false when the invoice isn't in Kanvas.
 */
#[AgentTool(name: 'Find Invoice', category: 'accounting')]
class FindInvoiceTool extends Tool implements HasRunKey
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_invoice',
            description: 'Look up a single invoice by its number and return the full detail: customer, document '
                . 'status (draft/issued/sent/paid/voided), collection state, total, amount paid, balance due, '
                . 'issued/due dates and line items. Use this when the user names a specific invoice number. '
                . 'Returns found=false when the invoice is not in the synced data.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The invoice number to look up.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $invoice_number): array
    {
        $result = $this->findTenantRecordOrNotFound(
            Invoice::class,
            'invoice_number',
            $invoice_number,
            'invoice'
        );

        if (is_array($result)) {
            return $result;
        }

        /** @var Invoice $invoice */
        $invoice = $result;

        return [
            'found' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'customer' => $invoice->billable_display_name,
            'document_status' => $invoice->document_status->value,
            'collection_state' => $invoice->collection_state?->value,
            'currency' => $invoice->currency,
            'total_native' => (float) $invoice->total_native,
            'paid_native' => (float) $invoice->paid_native,
            'balance_due_native' => (float) $invoice->balance_due_native,
            'issued_date' => $invoice->issued_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'lines' => $invoice->lines->map(fn ($l): array => [
                'description' => $l->description,
                'sku' => $l->sku,
                'quantity' => (float) $l->quantity,
                'unit_price_native' => (float) $l->unit_price_native,
            ])->all(),
        ];
    }
}
