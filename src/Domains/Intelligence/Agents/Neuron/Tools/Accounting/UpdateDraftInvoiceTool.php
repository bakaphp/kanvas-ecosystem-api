<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
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
use Kanvas\Scribe\Invoices\Actions\WithdrawInvoiceApprovalsAction;
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
        . 'and asked again, so the approver sees the new lines; the reply gives approval_request_id, or '
        . 'approve_by_target when the request is reached by target_type and target_id instead. An issued or sent '
        . 'invoice is not edited here: correct it '
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

        // Withdrawn before the edit, never after: an edit that outlives a failed withdrawal leaves the old
        // request approvable, and approving it issues lines its approver never saw.
        try {
            $wasAwaitingApproval = $this->withdrawApprovals($invoice) > 0;
        } catch (Throwable $e) {
            report($e);

            return $this->denied(
                "The approval waiting on invoice {$invoice_id} could not be withdrawn, so nothing was changed: {$e->getMessage()}",
                $this->approvalState($invoice),
                'Say the invoice was NOT changed and that its approval is as it was.'
            );
        }

        // Someone may have approved it while it was being withdrawn.
        $refusal = $this->whyNotEditable($invoice->refresh());
        if ($refusal !== null) {
            return $this->denied($refusal, guidance: 'Say the invoice was NOT changed and why.');
        }

        try {
            $invoice = new UpdateInvoiceAction(
                invoice: $invoice,
                data: $data,
                user: $this->contextUser(),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            // The rows are as they were, so the draft goes back to its approver rather than waiting on
            // nobody. The model is not: the action filled it before the rollback, and a request built
            // from it would describe an invoice that does not exist.
            $invoice->refresh();
            $approval = $wasAwaitingApproval ? $this->requestApproval($invoice) : 'none_pending';

            return $this->denied(
                "Updating invoice {$invoice_id} failed: {$e->getMessage()}",
                ['approval' => $approval, ...$this->approvalState($invoice)],
                'Say the invoice was NOT changed.'
            );
        }

        $this->announceAgentEdit($invoice);

        $approval = $wasAwaitingApproval ? $this->requestApproval($invoice) : 'none_pending';
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
                ...$this->approvalState($invoice),
            ],
            $invoice->document_status === InvoiceDocumentStatusEnum::DRAFT
                ? 'The draft now has the lines you gave. It is still a draft: nothing was issued or sent.'
                : 'The invoice has the lines you gave, and submitting it again issued it: it is no longer a draft.'
        );
    }

    /**
     * @return int how many requests were waiting
     */
    protected function withdrawApprovals(Invoice $invoice): int
    {
        return new WithdrawInvoiceApprovalsAction($invoice, 'draft edited')->execute();
    }

    /**
     * Read back, never assumed: the request found before the edit is closed, and quoting it would point
     * the person at a dead one.
     *
     * A tenant with no approval policy waits on the legacy queue, whose row ids the approval tools do
     * not take: there the request is reached by its target, so no id is given.
     *
     * @return array{approval_pending: bool, approval_request_id: ?int, approve_by_target: ?array{target_type: string, target_id: int}}
     */
    private function approvalState(Invoice $invoice): array
    {
        $request = $invoice->pendingApproval();
        $onLegacyQueue = $request === null && $invoice->pendingLegacyApprovals()->exists();

        return [
            'approval_pending' => $request !== null || $onLegacyQueue,
            'approval_request_id' => $request?->getId(),
            'approve_by_target' => $onLegacyQueue
                ? [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->getId(),
                ]
                : null,
        ];
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
     * A new request for the draft as it now stands. A failure leaves a draft nobody was asked about, which
     * is true by then (its old request is withdrawn) and which the result says.
     */
    private function requestApproval(Invoice $invoice): string
    {
        try {
            ApprovalOriginService::during(
                ApprovalOriginEnum::AGENT,
                fn (): Invoice => new SubmitInvoiceForApprovalAction($invoice, $this->contextUser())->execute(),
            );
        } catch (Throwable $e) {
            report($e);

            return 'not_requested: ' . $e->getMessage()
                . ' Tell the person the draft is waiting on nobody and has to be submitted again.';
        }

        return 'requested_again';
    }
}
