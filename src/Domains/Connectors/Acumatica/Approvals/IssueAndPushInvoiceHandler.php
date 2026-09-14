<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;
use Throwable;

/**
 * The synchronous half of approving an AR invoice: issue it, then push it to Acumatica.
 *
 * Same contract as the bill handler: a push failure comes back as data (`pushed`, `push_error`) so Arc
 * reports it rather than marking the tracking sheet Approved.
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
            'pushed' => false,
            'reference' => null,
            'push_error' => null,
        ];

        try {
            $result['reference'] = new PushInvoiceToAcumaticaAction($invoice)->execute();
            $result['pushed'] = true;
            $result['acumatica_id'] = (string) $invoice->get(CustomFieldEnum::INVOICE_ID->value, '');
        } catch (Throwable $e) {
            $result['push_error'] = $e->getMessage();
        }

        return $result;
    }
}
