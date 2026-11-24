<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE invoice (receivable — money a customer owes US): status, amounts (total / paid / balance), dates,
 * line items, its quote and where its approval stands. The AR mirror of find_bill.
 *
 * Drafts have no number until issued, so lookups also accept the ids returned by other tools and cards.
 */
#[AgentTool(name: 'Find Invoice', category: 'accounting')]
class FindInvoiceTool extends Tool
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use ResolvesInvoiceForTool;
    use TrackByInputs;

    protected string $name = 'find_invoice';

    protected ?string $description = 'Look up a single invoice by its Kanvas invoice_id (what create_ar_invoice, find_quote, '
        . 'the lists and the cards return; a DRAFT has no number yet, so this is the only way to find one) or by its '
        . 'invoice number, and return the full detail: customer, document status (draft/issued/sent/paid/voided), '
        . 'collection state, total, amount paid, balance due, dates, line items, the quote it came from, its fiscal '
        . 'number and where its approval stands. Returns found=false when no invoice matches.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice id. Use this for a draft or whenever a tool or card gave you the id.',
                required: false,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The invoice number, when the person names one and you have no id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $invoice_id = null, ?string $invoice_number = null): array
    {
        $invoice_number = trim((string) $invoice_number);

        if ($invoice_id === null && $invoice_number === '') {
            return [
                'found' => false,
                'message' => 'Pass invoice_id or invoice_number.',
            ];
        }

        $result = $invoice_id !== null
            ? $this->resolveInvoice($invoice_id)
            : $this->findTenantRecordOrNotFound(
                Invoice::class,
                'invoice_number',
                $invoice_number,
                'invoice'
            );

        // A person typing "24084" means the id they see; a draft has no number that could match it.
        if (is_array($result) && $invoice_id === null && ctype_digit($invoice_number)) {
            $result = $this->resolveInvoice((int) $invoice_number);
        }

        if (is_array($result)) {
            return [
                'found' => false,
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice_number !== '' ? $invoice_number : null,
                'message' => 'No invoice with that id or number for this company. Do not retry with the same value.',
            ];
        }

        /** @var Invoice $invoice */
        $invoice = $result;
        $approval = $invoice->approvalRequests()->latest('id')->first();

        return [
            'found' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'fiscal_number' => EcfCompliance::hasNumber($invoice) ? EcfCompliance::number($invoice) : null,
            'quote_id' => $invoice->quote_id,
            'customer' => $invoice->billable_display_name,
            'document_status' => $invoice->document_status->value,
            'collection_state' => $invoice->collection_state?->value,
            'approval' => $approval === null ? null : [
                'approval_request_id' => $approval->getId(),
                'status' => $approval->status->value,
                'action_error' => $approval->handlerError(),
            ],
            'currency' => $invoice->currency,
            'total_native' => (float) $invoice->total_native,
            'paid_native' => (float) $invoice->paid_native,
            'balance_due_native' => (float) $invoice->balance_due_native,
            'issued_date' => $invoice->issued_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'sku' => $line->sku,
                'quantity' => (float) $line->quantity,
                'unit_price_native' => (float) $line->unit_price_native,
                'discount_amount_native' => (float) $line->discount_amount_native,
                'tax_rate' => $line->tax_rate !== null ? (float) $line->tax_rate : null,
                'tax_amount_native' => (float) $line->tax_amount_native,
                'line_total_native' => (float) $line->line_total_native,
            ])->all(),
        ];
    }
}
