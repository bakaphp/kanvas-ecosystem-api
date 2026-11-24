<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueDraftCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueDraftDebitNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;

/**
 * Issues an approved AR invoice, credit note or debit note. Provider-neutral: for an e-CF company IssueInvoiceAction
 * takes the e-NCF and queues the provider submission; any ERP push hangs off the status-transition
 * workflow, never off this class.
 *
 * Refuses a draft that changed since it was submitted, so what gets issued is what the approver saw.
 * A refusal comes back as `handler_error` with the request approved and the draft still a draft with no
 * number; submitting it again opens a fresh request on the current figures.
 */
class IssueInvoiceHandler implements ApprovalHandlerInterface
{
    use ReadsApprovalSourceFields;

    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        /** @var Invoice|null $invoice */
        $invoice = $request->resolveEntity();

        if ($invoice === null) {
            throw new ValidationException("Invoice {$request->entity_id} no longer exists.");
        }

        // A rule-closed request (no step applied to this origin) has no approver; it acts as whoever asked.
        $actor = $approver ?? $request->requestedByUser;

        if ($actor === null) {
            throw new ValidationException('Issuing an invoice requires an approving user.');
        }

        $this->assertUnchanged($request, $invoice);

        $invoice = match (true) {
            $invoice->isCreditNote() => new IssueDraftCreditNoteAction($invoice, $actor)->execute(),
            $invoice->isDebitNote() => new IssueDraftDebitNoteAction($invoice, $actor)->execute(),
            default => new IssueInvoiceAction($invoice, $invoice->customer, $actor)->execute(),
        };

        return [
            'target_type' => $invoice->document_type->value,
            'target_id' => $invoice->getId(),
            'label' => $invoice->invoice_number,
            'encf' => EcfCompliance::number($invoice),
            ...$this->sourceFields($invoice),
        ];
    }

    private function assertUnchanged(ApprovalRequest $request, Invoice $invoice): void
    {
        $frozen = $request->payload[InvoiceApprovalPayload::HASH_KEY] ?? null;

        // Requests opened before the hash existed carry none; there is nothing to compare them against.
        if (! is_string($frozen) || $frozen === '') {
            return;
        }

        if (! hash_equals($frozen, InvoiceApprovalPayload::currentHash($invoice))) {
            throw new ValidationException(
                "Invoice {$invoice->getId()} changed after it was submitted for approval (customer, tax id, "
                . 'e-CF type, ITBIS, total or lines), so it was not issued. Submit it again so the approver '
                . 'signs what will actually be sent.'
            );
        }
    }
}
