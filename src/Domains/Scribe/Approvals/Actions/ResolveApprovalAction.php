<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * @deprecated Use Kanvas\Approvals\Actions\ApproveAction.
 *
 * Only reachable for a tenant with no approval policy, since a migrated tenant never gets an
 * ApprovalQueueItem to resolve. Its match arms live on as registered handler classes named by a
 * policy row, so nothing new should be added here — a new approval type is a policy row plus a
 * handler, not another case.
 *
 * Resolves a pending ApprovalQueueItem by dispatching on action_type to the domain action that
 * knows how to carry it out.
 */
class ResolveApprovalAction
{
    public function __construct(
        protected readonly ApprovalQueueItem $item,
        protected readonly UserInterface $approver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if ($this->item->status !== ApprovalQueueStatusEnum::PENDING) {
            throw new ValidationException(
                "This approval request is already {$this->item->status->value}, not pending."
            );
        }

        $result = match ($this->item->action_type) {
            'approve_bill' => $this->resolveBill(),
            'approve_invoice' => $this->resolveInvoice(),
            default => throw new ValidationException(
                "No approval handler registered for action_type \"{$this->item->action_type}\"."
            ),
        };

        $this->item->status = ApprovalQueueStatusEnum::APPROVED;
        $this->item->approved_by_users_id = $this->approver->getId();
        $this->item->approved_at = Carbon::now();
        $this->item->save();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBill(): array
    {
        /** @var Bill|null $bill */
        $bill = Bill::query()->where('id', $this->item->target_id)->first();

        if ($bill === null) {
            throw new ValidationException("Bill {$this->item->target_id} no longer exists.");
        }

        /** @var Organization $vendor */
        $vendor = Organization::query()->where('id', $bill->vendor_organization_id)->first();

        $bill = new ApproveBillAction($bill, $vendor, $this->approver)->execute();

        $result = [
            'target_type' => 'bill',
            'target_id' => $bill->getId(),
            'label' => $bill->bill_number,
            ...$this->sourceFields($bill),
            'pushed' => false,
            'reference' => null,
            'push_error' => null,
        ];

        try {
            $result['reference'] = new PushBillToAcumaticaAction($bill)->execute();
            $result['pushed'] = true;
            $result['acumatica_id'] = (string) $bill->get(AcumaticaCustomFieldEnum::BILL_ID->value, '');
        } catch (AcumaticaWriteException|Throwable $e) {
            $result['push_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInvoice(): array
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->where('id', $this->item->target_id)->first();

        if ($invoice === null) {
            throw new ValidationException("Invoice {$this->item->target_id} no longer exists.");
        }

        /** @var Organization $customer */
        $customer = Organization::query()->where('id', $invoice->customer_organization_id)->first();

        $invoice = new IssueInvoiceAction($invoice, $customer, $this->approver)->execute();

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
            $result['acumatica_id'] = (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_ID->value, '');
        } catch (AcumaticaWriteException|Throwable $e) {
            $result['push_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return array{source_email_message_id: ?string, source_attachment_url: ?string, source_attachment_filename: ?string}
     */
    private function sourceFields(Bill|Invoice $record): array
    {
        return [
            'source_email_message_id' => $this->customField($record, ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID),
            'source_attachment_url' => $this->customField($record, ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL),
            'source_attachment_filename' => $this->customField($record, ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME),
        ];
    }

    private function customField(Bill|Invoice $record, ApprovalCustomFieldEnum $field): ?string
    {
        $value = (string) $record->get($field->value, '');

        return $value !== '' ? $value : null;
    }
}
