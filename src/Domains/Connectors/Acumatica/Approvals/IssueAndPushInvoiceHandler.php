<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;

/**
 * Issues an AR invoice in Kanvas. Status-transition workflows handle external synchronization.
 */
class IssueAndPushInvoiceHandler implements ApprovalHandlerInterface
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

        if ($approver === null) {
            throw new ValidationException('Issuing an invoice requires an approving user.');
        }

        $invoice = new IssueInvoiceAction($invoice, $invoice->customer, $approver)->execute();

        $result = [
            'target_type' => 'invoice',
            'target_id' => $invoice->getId(),
            'label' => $invoice->invoice_number,
            ...$this->sourceFields($invoice),
        ];

        return $result;
    }
}
