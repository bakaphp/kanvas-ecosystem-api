<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Voids an issued AR invoice directly in the Kanvas ledger. */
#[AgentTool(name: 'Void AR Invoice', category: 'accounting')]
class VoidArInvoiceTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'void_ar_invoice';

    protected ?string $description = 'Voids an issued or sent AR invoice in the Kanvas ledger and reverses its journal '
        . 'entry. Paid invoices cannot be voided; issue a credit note instead. Use only when the user explicitly '
        . 'asks to void the invoice.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::NUMBER,
                description: 'The Kanvas invoice id to void (returned as invoice_id by create_ar_invoice).',
                required: true,
            ),
            new ToolProperty(
                name: 'reason_code',
                type: PropertyType::STRING,
                description: 'Reason for voiding. Defaults to requested_by_user.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, ?string $reason_code = null): array
    {
        $app = $this->app;

        $invoice = Invoice::query()
            ->where('id', $invoice_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'voided' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoice_id} for this app/company.",
            ];
        }

        try {
            $voidedInvoice = new VoidInvoiceAction(
                invoice: $invoice,
                voidReasonCode: trim((string) $reason_code) ?: 'requested_by_user',
                user: $this->user,
            )->execute();
        } catch (Throwable $e) {
            return [
                'voided' => false,
                'invoice_id' => $invoice->getId(),
                'reason' => 'void_failed',
                'message' => 'Voiding the invoice in the Kanvas ledger failed: ' . $e->getMessage(),
            ];
        }

        return [
            'voided' => true,
            'invoice_id' => $invoice->getId(),
            'document_status' => $voidedInvoice->document_status->value,
            'next' => 'The invoice was voided in the Kanvas ledger and its journal entry was reversed. '
                . 'Any external synchronization is handled by configured workflows.',
        ];
    }
}
