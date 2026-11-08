<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GeneratesScribeDocumentPdfForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Delivery\Services\DocumentFilesService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfPdfSourceEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfInvoicePdfService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Renders an invoice or credit note as a printable PDF and attaches it to the document. */
#[AgentTool(name: 'Generate Invoice PDF', category: 'accounting')]
class GenerateInvoicePdfTool extends Tool
{
    use GeneratesScribeDocumentPdfForTool;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'generate_invoice_pdf';

    protected ?string $description = 'Renders an invoice (or credit note) as a PDF — customer block, lines, totals, amount '
        . 'paid and balance due — and attaches it to the invoice in Kanvas. Use it when someone asks '
        . 'for the invoice as a document to send or review. This renders what Kanvas holds; it is not '
        . 'the vendor PDF an invoice email arrived with. For an e-CF it returns the legal PDF with DGII\'s QR and '
        . 'security code, which exists only once DGII accepted it, and never renders a plain copy.';

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

        $fiscal = EcfCompliance::current($invoice);
        if ($fiscal !== null) {
            return $this->ecfPdf($invoice, $fiscal);
        }

        return [
            ...$this->generateDocumentPdf($invoice, $template_name),
            'invoice_number' => $invoice->invoice_number,
        ];
    }

    /**
     * An e-CF invoice's only valid PDF carries DGII's stamp and QR, which exist once DGII accepted it. A plain
     * render looks like the invoice but is not a fiscal document, so it is never produced for one.
     *
     * @return array<string, mixed>
     */
    private function ecfPdf(Invoice $invoice, FiscalDocument $fiscal): array
    {
        if (! $fiscal->status->isAccepted()) {
            return [
                'generated' => false,
                'reason' => 'ecf_not_accepted',
                'ecf_status' => $fiscal->status->value,
                'message' => "Invoice {$invoice->invoice_number} is an e-CF and DGII has not accepted it ({$fiscal->status->value}), "
                    . 'so its PDF does not exist yet: the QR and security code come with the acceptance. Check ecf_status; '
                    . 'do not offer a plain PDF in its place, it would not be a valid fiscal document.',
            ];
        }

        $user = $this->contextUser();
        $prefersOurs = EcfPdfSourceEnum::forCompany($invoice->company, $fiscal->provider) === EcfPdfSourceEnum::KANVAS;

        try {
            $file = $prefersOurs
                ? $invoice->getFileByName(EcfInvoicePdfService::FIELD_NAME)?->filesystem
                : new DocumentFilesService()->ecfPdf($invoice);
            $file ??= $user !== null ? new EcfInvoicePdfService($invoice)->generate($user) : null;
        } catch (Throwable $e) {
            report($e);
            $file = null;
        }

        if ($file === null) {
            return [
                'generated' => false,
                'reason' => 'ecf_pdf_unavailable',
                'message' => "DGII accepted invoice {$invoice->invoice_number} but its e-CF PDF is not available right now. Say so; do not offer a plain PDF instead.",
            ];
        }

        return [
            'generated' => true,
            'filesystem_id' => $file->getId(),
            'file_url' => $file->url,
            'file_name' => $file->name,
            'document_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'is_ecf' => true,
            'message' => 'This is the legal e-CF PDF with DGII\'s QR and security code (' . ($prefersOurs ? 'Kanvas layout' : 'provider copy') . '). '
                . 'Hand the person a link with get_file_link, never the raw id.',
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
