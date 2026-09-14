<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GeneratesScribeDocumentPdfForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Renders an invoice or credit note as a printable PDF and attaches it to the document. */
#[AgentTool(name: 'Generate Invoice PDF', category: 'accounting')]
class GenerateInvoicePdfTool extends Tool implements HasRunKey
{
    use GeneratesScribeDocumentPdfForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'generate_invoice_pdf',
            description: 'Renders an invoice (or credit note) as a PDF — customer block, lines, totals, amount '
                . 'paid and balance due — and attaches it to the invoice in Kanvas. Use it when someone asks '
                . 'for the invoice as a document to send or review. This renders what Kanvas holds; it is not '
                . 'the vendor PDF an invoice email arrived with.',
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
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice id (returned as invoice_id by create_ar_invoice or '
                    . 'find_invoice). Pass this or invoice_number.',
                required: false,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The invoice number, when that is what you have. Ignored when invoice_id is given.',
                required: false,
            ),
            new ToolProperty(
                name: 'template_name',
                type: PropertyType::STRING,
                description: 'Name of a stored template to render instead of the standard layout. Only pass '
                    . 'one the user actually asked for — the default layout is the right answer otherwise.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $invoice_id = null, ?string $invoice_number = null, ?string $template_name = null): array
    {
        $invoice = $this->resolveInvoice($invoice_id, $invoice_number);

        if (is_array($invoice)) {
            return ['generated' => false, ...$invoice];
        }

        return [
            ...$this->generateDocumentPdf($invoice, $template_name),
            'invoice_number' => $invoice->invoice_number,
        ];
    }

    /**
     * @return Invoice|array{reason: string, message: string}
     */
    private function resolveInvoice(?int $invoiceId, ?string $invoiceNumber): Invoice|array
    {
        if ($invoiceId === null && trim((string) $invoiceNumber) === '') {
            return [
                'reason' => 'identifier_required',
                'message' => 'Pass invoice_id (preferred) or invoice_number — there is no other way to name '
                    . 'the invoice.',
            ];
        }

        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('invoice');
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when(
                $invoiceId !== null,
                fn (Builder $query): Builder => $query->where('id', $invoiceId),
                fn (Builder $query): Builder => $query->where('invoice_number', trim((string) $invoiceNumber))
            )
            ->first();

        if ($invoice === null) {
            return [
                'reason' => 'invoice_not_found',
                'message' => 'No invoice matching ' . ($invoiceId !== null ? "id {$invoiceId}" : "number \"{$invoiceNumber}\"")
                    . ' for this app/company.',
            ];
        }

        return $invoice;
    }
}
