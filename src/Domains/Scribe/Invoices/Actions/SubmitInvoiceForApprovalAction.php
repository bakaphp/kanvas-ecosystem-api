<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Actions\SystemApproveAction;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Actions\RequestApprovalAction;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\Approvals\InvoiceApprovalPayload;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Invoices stay DRAFT during approval; there is no PENDING_APPROVAL invoice status.
 * e-CF documents require a generic approval policy. When none of its steps applies to the recorded
 * origin, system approval issues the document immediately.
 */
class SubmitInvoiceForApprovalAction
{
    public function __construct(public readonly Invoice $invoice, public readonly ?UserInterface $user = null, protected readonly EcfIssuanceService $ecf = new EcfIssuanceService())
    {
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

        $payload = InvoiceApprovalPayload::for($this->invoice, $this->ecf);

        if (! $this->ecf->issuesEcf($this->invoice)) {
            new RequestApprovalAction(
                entity: $this->invoice,
                targetType: 'invoice',
                requestedByUser: $this->user,
                payload: $payload,
            )->execute();

            return $this->invoice->refresh();
        }

        $this->ecf->assertBuyerIsRegistered($this->invoice);

        $targetType = $this->invoice->document_type->value;
        $request = $this->invoice->requestApproval(
            ApprovalRequest::approvalTypeFor($targetType),
            payload: $payload,
            requestedBy: $this->user,
            origin: ApprovalOriginService::current(),
        ) ?? throw new ValidationException(
            "This company issues e-CF but has no {$targetType} approval policy, so nobody could approve it. "
            . 'Seed one with kanvas-scribe:seed-ecf-approval-policy.'
        );

        if ((bool) ($request->metadata['no_live_steps'] ?? false)) {
            new SystemApproveAction($request, "No approval step applies to a {$targetType} from origin '{$request->origin->value}'.")->execute();
        }

        return $this->invoice->refresh();
    }
}
