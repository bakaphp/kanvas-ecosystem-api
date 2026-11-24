<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Drafts a credit or debit note against one issued invoice and submits it for a person's approval. An
 * agent never issues a note itself (plan §10 decision 2): on an e-CF company the approval is always on,
 * and the E34/E33 number is only spent when a person signs.
 */
abstract class AbstractDraftInvoiceNoteTool extends Tool
{
    use HasKanvasContext;
    use ResolvesInvoiceForTool;

    abstract protected function noteLabel(): string;

    abstract protected function noteType(): DocumentTypeEnum;

    abstract protected function draftNote(Invoice $parent, InvoiceData $data): Invoice;

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of the issued invoice the note modifies (from find_invoice). Never guess it.',
                required: true,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'The amounts the note itself carries, one entry per line, positive numbers. Not the '
                    . 'invoice\'s new totals: only the difference.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'One note line.',
                    properties: [
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'What the line corrects, e.g. the invoice line\'s product.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'Quantity. Defaults to 1.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'unit_price',
                            type: PropertyType::NUMBER,
                            description: 'Unit amount before ITBIS.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'tax_amount',
                            type: PropertyType::NUMBER,
                            description: 'ITBIS on this line in the document currency. 0 when exempt.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'tax_rate',
                            type: PropertyType::NUMBER,
                            description: 'ITBIS rate as a percent (18, 16, 0). Optional.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the note is issued, in the customer\'s language; it prints on the document.',
                required: true,
            ),
        ];
    }

    /**
     * @param array<int, array{description?: string, quantity?: float|int|string, unit_price?: float|int|string, tax_amount?: float|int|string|null, tax_rate?: float|int|string|null}> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, array $lines, string $reason): array
    {
        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): array => $this->draft($invoice_id, $lines, $reason)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(int $invoiceId, array $lines, string $reason): array
    {
        $parent = $this->resolveInvoice($invoiceId);

        if (is_array($parent)) {
            return ['drafted' => false, ...$parent];
        }

        $waiting = Invoice::query()
            ->where('parent_invoice_id', $parent->getId())
            ->where('document_type', $this->noteType()->value)
            ->where('document_status', InvoiceDocumentStatusEnum::DRAFT->value)
            ->notDeleted()
            ->first();

        if ($waiting !== null) {
            return [
                'drafted' => false,
                'reason' => 'draft_already_waiting',
                'note_id' => $waiting->getId(),
                'pending_approval' => $waiting->pendingApproval() !== null,
                'message' => "{$this->noteLabel()} {$waiting->getId()} is already drafted against this invoice and not issued. "
                    . 'Do not draft another: ask whether it was approved, check it with ecf_status, or edit that draft.',
            ];
        }

        $reason = Str::trimToNull($reason);

        if ($reason === null) {
            return ['drafted' => false, 'reason' => 'reason_required', 'message' => 'Give the reason for the ' . $this->noteLabel() . '; it prints on the document.'];
        }

        $lineData = [];
        foreach ($lines as $line) {
            $unitPrice = (float) ($line['unit_price'] ?? 0);

            if ($unitPrice <= 0) {
                return ['drafted' => false, 'reason' => 'invalid_line', 'message' => 'Every line needs a positive unit_price. Send only the difference, as a positive number.'];
            }

            $lineData[] = new InvoiceLineData(
                description: Str::trimToNull((string) ($line['description'] ?? '')) ?? $reason,
                quantity: max(0.01, (float) ($line['quantity'] ?? 1)),
                unit_price_native: $unitPrice,
                tax_rate: isset($line['tax_rate']) && is_numeric($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount'] ?? 0),
            );
        }

        if ($lineData === []) {
            return ['drafted' => false, 'reason' => 'lines_required', 'message' => 'At least one line is required.'];
        }

        try {
            $note = $this->draftNote($parent, new InvoiceData(
                app: $this->app,
                company: $this->company,
                billable: null,
                lines: new DataCollection(InvoiceLineData::class, $lineData),
                currency: $parent->currency,
                fx_rate_to_base: (float) $parent->fx_rate_to_base,
                notes: $reason,
            ));

            $note = new SubmitInvoiceForApprovalAction(invoice: $note, user: $this->user)->execute();
        } catch (Throwable $e) {
            return [
                'drafted' => false,
                'reason' => 'draft_failed',
                'message' => "The {$this->noteLabel()} was not created: {$e->getMessage()}",
            ];
        }

        $pending = $note->pendingApproval() !== null;

        return [
            'drafted' => true,
            'note_id' => $note->getId(),
            'document_type' => $note->document_type->value,
            'parent_invoice_id' => $parent->getId(),
            'parent_invoice_number' => $parent->invoice_number,
            'total' => (float) $note->total_native,
            'currency' => $note->currency,
            'status' => $note->document_status->value,
            'pending_approval' => $pending,
            'encf' => EcfCompliance::number($note),
            'next' => $pending
                ? "The {$this->noteLabel()} is a draft waiting for a person's approval; it is issued (and numbered) when they approve it. Say so; do not claim it was issued."
                : "No approval step applied, so the {$this->noteLabel()} was issued.",
        ];
    }
}
