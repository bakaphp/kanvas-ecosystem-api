<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\VerifiesApprovalAuthority;
use Kanvas\Scribe\Approvals\Actions\ResolveApprovalAction;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Approves any pending item in the approval queue — a bill, an invoice, or any future action type
 * registered in ResolveApprovalAction — and carries out whatever that approval means (e.g. pushing
 * to Acumatica). Generic on purpose: new approval types plug into ResolveApprovalAction without
 * ever touching this tool.
 */
#[AgentTool(name: 'Approve Pending Item', category: 'accounting')]
class ApprovePendingItemTool extends Tool
{
    use HasKanvasContext;
    use VerifiesApprovalAuthority;

    public function __construct()
    {
        parent::__construct(
            name: 'approve_pending_item',
            description: 'Approves a pending item in the approval queue (e.g. a bill left pending by '
                . 'create_ap_bill, or an invoice left pending by create_ar_invoice) and carries out its action — '
                . 'for a bill/invoice, that means approving it in Kanvas and pushing it to Acumatica. Works for '
                . 'any approval type in the queue, not just invoices. Only the configured approver may call this '
                . '— call it only when that specific person explicitly asks to approve something, never on your '
                . 'own initiative or on behalf of anyone else.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'target_type',
                type: PropertyType::STRING,
                description: 'The kind of record pending approval, e.g. "bill" or "invoice".',
                required: true,
            ),
            new ToolProperty(
                name: 'target_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of that record — the bill_id from create_ap_bill, or the invoice_id '
                    . 'from create_ar_invoice.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $target_type, int $target_id): array
    {
        if (! $this->isAuthorizedApprover()) {
            return [
                'approved' => false,
                'reason' => 'not_authorized',
                'message' => 'Only the configured approver can approve this.',
            ];
        }

        $item = ApprovalQueueItem::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('target_type', $target_type)
            ->where('target_id', $target_id)
            ->where('status', ApprovalQueueStatusEnum::PENDING->value)
            ->latest('id')
            ->first();

        if ($item === null) {
            return [
                'approved' => false,
                'reason' => 'not_found',
                'message' => "No pending approval found for {$target_type} {$target_id}.",
            ];
        }

        try {
            $result = new ResolveApprovalAction($item, $this->user)->execute();
        } catch (ValidationException|Throwable $e) {
            return [
                'approved' => false,
                'reason' => 'resolve_failed',
                'message' => $e->getMessage(),
            ];
        }

        $approvedBy = (string) $this->user->email;
        $approvedAt = Carbon::now()->toDateString();
        $evidence = "Approved by {$approvedBy} on {$approvedAt}";

        return array_merge($result, [
            'approved' => true,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'next' => $result['pushed']
                ? "Pushed to Acumatica. Now: (1) add a note with the approval evidence (\"{$evidence}\"). "
                    . '(2) If source_attachment_url is present, attach it (attach_bill_file/attach_invoice_file) '
                    . 'now that this record is actually pushed. '
                    . '(3) If source_email_message_id is present, reply_to_email with that same '
                    . 'evidence on the original invoice email. '
                    . '(4) In the sheet, find the row for this record and update column D (Status) to '
                    . '"Approved", column E (Approved Date) to approved_at, and column F (Approved By) to '
                    . 'approved_by.'
                : 'Approved in Kanvas but the push to Acumatica failed: ' . ($result['push_error'] ?? 'unknown error')
                    . '. It needs manual attention.',
        ]);
    }
}
