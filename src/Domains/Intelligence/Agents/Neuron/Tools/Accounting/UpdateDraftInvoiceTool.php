<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\TakesInvoiceLines;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Tools\Traits\AnnouncesAgentEdits;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Update Draft Invoice', category: 'accounting')]
class UpdateDraftInvoiceTool extends Tool
{
    use AnnouncesAgentEdits;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesInvoiceForTool;
    use TakesInvoiceLines;
    use TrackByInputs;

    /** A billing schedule sized its other payments against this one, so an edit may not move it by a cent. */
    private const float TOTAL_TOLERANCE = 0.005;

    protected string $name = 'update_draft_invoice';

    protected ?string $description = 'Replaces the lines of a DRAFT invoice, and its memo when given. This is how a draft '
        . 'is corrected: never discard it to create another. The lines you give are the whole invoice afterwards, so '
        . 'include the ones that stay. A draft made by a billing schedule (a payment of a quote\'s SOW) keeps its total: '
        . 'reword, merge or split its lines, but they must add up to the same amount. A pending approval is withdrawn '
        . 'and asked again, so the approver sees the new lines. An issued or sent invoice is not edited here: correct it '
        . 'with draft_credit_note / draft_debit_note or void_ar_invoice. A draft that already carries a fiscal number '
        . 'or was already approved cannot be changed.';

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
                description: 'The Kanvas invoice id of the draft (from find_invoice, create_ar_invoice or a quote\'s invoices).',
                required: true,
            ),
            $this->invoiceLinesProperty(
                'Every line the invoice has after the edit. On a company that issues e-CF every line must give '
                . 'tax_rate (18 for ITBIS, 0 when exempt).',
                required: true,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'New memo for the invoice. Leave empty to keep the one it has.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, array $lines, ?string $memo = null): array
    {
        $invoice = $this->resolveInvoice($invoice_id);
        if (is_array($invoice)) {
            return $this->notFound($invoice, guidance: 'Nothing was changed. Confirm the id with find_invoice.');
        }

        $refusal = $this->whyNotEditable($invoice);
        if ($refusal !== null) {
            return $this->denied($refusal, guidance: 'Say the invoice was NOT changed and why.');
        }

        if ($lines === []) {
            return $this->invalidArgs('Give at least one line. Nothing was changed.');
        }

        $memo = Str::trimToNull($memo);
        $lineData = $this->invoiceLinesFrom(
            $lines,
            $memo ?? $invoice->notes,
            new EcfIssuanceService()->issuesEcf($invoice),
            'Nothing was changed.'
        );

        if (is_string($lineData)) {
            return $this->invalidArgs($lineData);
        }

        $data = InvoiceData::forUpdate($invoice, $lineData, $memo);
        $total = $data->totals()[0]['total'];
        $scheduled = (float) $invoice->total_native;

        if ($invoice->billingScheduleId() !== null && abs($total - $scheduled) > self::TOTAL_TOLERANCE) {
            return $this->denied(
                sprintf(
                    'Invoice %d is a payment of a billing schedule and keeps its total of %.2f %s; the lines given add up '
                    . 'to %.2f. The schedule\'s other payments are sized against this one.',
                    $invoice->getId(),
                    $scheduled,
                    $invoice->currency,
                    $total,
                ),
                guidance: 'Nothing was changed. Give lines that add up to the same total, tax included.'
            );
        }

        $awaitedApproval = $invoice->pendingApprovals();

        try {
            $invoice = new UpdateInvoiceAction(
                invoice: $invoice,
                data: $data,
                user: $this->contextUser(),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->denied(
                "Updating invoice {$invoice_id} failed: {$e->getMessage()}",
                guidance: 'Say the invoice was NOT changed.'
            );
        }

        $this->announceAgentEdit($invoice);

        $approval = $awaitedApproval->isEmpty()
            ? 'none_pending'
            : $this->askApprovalAgain($invoice, $awaitedApproval);
        // A policy that no longer asks anyone issues the invoice on submission, so the status is read after it.
        $invoice->refresh();

        return $this->ok(
            [
                'invoice_id' => $invoice->getId(),
                'quote_id' => $invoice->quote_id,
                'document_status' => $invoice->document_status->value,
                'currency' => $invoice->currency,
                'total' => (float) $invoice->total_native,
                'lines' => $invoice->lines()->count(),
                'approval' => $approval,
                // The one found before the edit is cancelled; quoting it would point the person at a dead request.
                'approval_request_id' => $invoice->pendingApproval()?->getId(),
            ],
            $invoice->document_status === InvoiceDocumentStatusEnum::DRAFT
                ? 'The draft now has the lines you gave. It is still a draft: nothing was issued or sent.'
                : 'The invoice has the lines you gave, and submitting it again issued it: it is no longer a draft.'
        );
    }

    private function whyNotEditable(Invoice $invoice): ?string
    {
        if ($invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            return "Invoice {$invoice->getId()} is {$invoice->document_status->value}, not a draft: correct it with a "
                . 'credit or debit note, or void it.';
        }

        if (EcfCompliance::hasNumber($invoice)) {
            return "Invoice {$invoice->getId()} already holds fiscal number " . EcfCompliance::number($invoice)
                . ', so its content is fixed.';
        }

        // An approved draft is on its way to being issued (or failed there and will be retried): new
        // lines would go out under an approval that never saw them.
        if ($invoice->isApproved()) {
            return "Invoice {$invoice->getId()} was already approved, so its lines cannot change under that approval.";
        }

        return null;
    }

    /**
     * The request the approver holds describes the lines that were replaced, so it is withdrawn and a new
     * one opened. A failure here leaves an edited draft nobody was asked about, which the result says.
     *
     * @param Collection<int, ApprovalRequest> $awaited
     */
    private function askApprovalAgain(Invoice $invoice, Collection $awaited): string
    {
        try {
            $awaited->each(
                fn (ApprovalRequest $request) => new CancelApprovalAction($request, 'draft edited')->execute()
            );

            ApprovalOriginService::during(
                ApprovalOriginEnum::AGENT,
                fn (): Invoice => new SubmitInvoiceForApprovalAction($invoice, $this->contextUser())->execute(),
            );
        } catch (Throwable $e) {
            report($e);

            return 'not_requested: ' . $e->getMessage()
                . ' Tell the person the edited draft is waiting on nobody and has to be submitted again.';
        }

        return 'requested_again';
    }
}
