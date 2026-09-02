<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Actions\RequestApprovalAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Parks a draft invoice for sign-off, the AR counterpart of SubmitBillForApprovalAction.
 *
 * It exists because gating used to live inside CreateArInvoiceTool, which meant an invoice created any
 * other way — a GraphQL mutation, an import, a future tool — was issued with nothing asking anyone.
 * Approval belongs to the domain, not to one caller.
 *
 * Unlike a bill there is no PENDING_APPROVAL document status to move to: an invoice stays DRAFT until
 * IssueInvoiceAction runs, which is exactly what the approval handler does on sign-off. The status
 * assertion here is the guard — an invoice already issued has nothing left to approve.
 */
class SubmitInvoiceForApprovalAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new ValidationException(
                "Invoice {$this->invoice->getId()} is {$this->invoice->document_status->value}, "
                . 'so there is nothing to approve.'
            );
        }

        // Idempotent: re-submitting an invoice already waiting must not ask everyone twice.
        if ($this->invoice->pendingApproval() !== null) {
            return $this->invoice;
        }

        new RequestApprovalAction(
            entity: $this->invoice,
            targetType: 'invoice',
            requestedByUser: $this->user,
            payload: [
                'total_native' => (float) $this->invoice->total_native,
                'currency' => $this->invoice->currency,
                'customer_organization_id' => $this->invoice->customer_organization_id,
            ],
        )->execute();

        return $this->invoice->refresh();
    }
}
