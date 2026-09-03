<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Re-sends a pending bill's invoice PDF to its configured approver(s) on Slack — for when the
 * original approval request went out without it. Only works when create_ap_bill actually captured
 * a source_attachment_url at creation time; reports plainly when there is nothing on file to resend.
 */
#[AgentTool(name: 'Resend Bill Attachment', category: 'accounting')]
class ResendBillAttachmentTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'resend_bill_attachment',
            description: 'Re-sends a pending bill\'s invoice PDF to its configured approver(s) on Slack. Use '
                . 'this when an approver says they did not receive the attachment with their approval request. '
                . 'Only works if source_attachment_url was captured when the bill was created — reports plainly '
                . 'when there is nothing on file to resend, rather than failing silently.',
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
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas bill id, from create_ap_bill or the approver\'s own message. Never guess it.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id): array
    {
        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'resent' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        $attachmentUrl = trim((string) $bill->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL->value, ''));

        if ($attachmentUrl === '') {
            return [
                'resent' => false,
                'reason' => 'no_attachment_on_file',
                'message' => "Bill {$bill_id} has no source_attachment_url on file — there is nothing to resend.",
            ];
        }

        $vendor = $bill->vendor;
        $approverEmails = $vendor !== null ? ResolveApproverEmailAction::resolveForOrganization($vendor) : [];

        if ($approverEmails === []) {
            return [
                'resent' => false,
                'reason' => 'no_approver_configured',
                'message' => "No approver configured for bill {$bill_id}'s vendor — there is nobody to send it to.",
            ];
        }

        $attachmentFilename = trim((string) $bill->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME->value, '')) ?: null;

        NotifyApproverAction::notifyAll(
            approverEmails: $approverEmails,
            app: $this->app,
            text: "Here is the invoice for bill {$bill_id}, resent on request.",
            attachmentUrl: $attachmentUrl,
            attachmentFilename: $attachmentFilename,
        );

        return [
            'resent' => true,
            'bill_id' => $bill_id,
            'sent_to' => $approverEmails,
        ];
    }
}
