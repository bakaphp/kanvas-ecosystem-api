<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Sends a corrected DRAFT AP bill to the approvals queue; it never approves. */
#[AgentTool(name: 'Submit AP Bill For Approval', category: 'accounting')]
class SubmitApBillTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'submit_ap_bill_for_approval';

    protected ?string $description = 'Sends a DRAFT AP bill to the approvals queue so its approver can approve or '
        . 'reject it — for example a bill an email invoice left in draft that was then corrected with '
        . 'update_ap_bill. The bill needs a vendor. It never approves the bill: a person does that in the '
        . 'approvals window. Only call it when the person says to send that bill for approval.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas bill id (bill_id from create_ap_bill, find_bill or list_open_bills).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id): array
    {
        try {
            $bill = Bill::getByIdFromCompanyApp($bill_id, $this->company, $this->app);
        } catch (ExceptionsModelNotFoundException) {
            return [
                'submitted' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        if ($bill->document_status === BillDocumentStatusEnum::PENDING_APPROVAL) {
            return [
                'submitted' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'already_pending_approval',
                'message' => "Bill {$bill_id} is already waiting for approval.",
            ];
        }

        if ($bill->document_status !== BillDocumentStatusEnum::DRAFT) {
            return [
                'submitted' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'not_a_draft',
                'document_status' => $bill->document_status->value,
                'message' => "Bill {$bill_id} is {$bill->document_status->value}; only a draft can be sent "
                    . 'for approval.',
            ];
        }

        if ($bill->vendor_organization_id === null) {
            return [
                'submitted' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'vendor_required',
                'message' => 'This bill has no vendor yet, so nobody can approve it. The vendor has to be set on '
                    . 'the bill first (create_organization / the bill screen); this tool does not change the vendor.',
            ];
        }

        try {
            $submitted = ApprovalOriginService::during(
                ApprovalOriginEnum::AGENT,
                fn (): Bill => new SubmitBillForApprovalAction($bill, $this->user)->execute(),
            );
        } catch (Throwable $e) {
            return [
                'submitted' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'submit_failed',
                'message' => 'Could not send the bill for approval: ' . $e->getMessage(),
            ];
        }

        $request = $submitted->pendingApproval();
        $routed = $request !== null && ! $request->isUnassigned();

        return [
            'submitted' => true,
            'bill_id' => $submitted->getId(),
            'bill_number' => $submitted->bill_number,
            'document_status' => $submitted->document_status->value,
            'amount' => (float) $submitted->total_native,
            'currency' => $submitted->currency,
            'approved_by_flag' => $routed || $request === null ? '' : 'NOT IN APPROVER LIST',
            'next' => match (true) {
                $request === null => 'The bill is pending approval; no approval step was applied.',
                $routed => 'Sent for approval (status: pending_approval); the approver was notified and decides '
                    . 'in the approvals window.',
                default => 'Sent for approval, but no approver could be resolved for this vendor, so nobody was '
                    . 'notified. Tell the person to have an admin set the vendor\'s approver (or the accounting '
                    . 'default approver).',
            },
        ];
    }
}
