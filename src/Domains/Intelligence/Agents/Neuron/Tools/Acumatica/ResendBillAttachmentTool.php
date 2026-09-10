<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;

/**
 * Re-sends a pending bill's invoice PDF to its configured approver(s) on Slack — for when the
 * original approval request went out without it. Only works when the bill actually has an invoice
 * PDF on file; reports plainly when there is nothing to resend.
 */
#[AgentTool(name: 'Resend Bill Attachment', category: 'accounting')]
class ResendBillAttachmentTool extends AbstractResendApprovalAttachmentTool
{
    public function __construct()
    {
        parent::__construct(
            name: 'resend_bill_attachment',
            description: 'Re-sends a pending bill\'s invoice PDF to its configured approver(s) on Slack. Use '
                . 'this when an approver says they did not receive the attachment with their approval request. '
                . 'Only works if an invoice PDF was captured when the bill was created — reports plainly when '
                . 'there is nothing on file to resend, rather than failing silently.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id): array
    {
        return $this->resendAttachment($bill_id);
    }

    #[Override]
    protected function noun(): string
    {
        return 'bill';
    }

    #[Override]
    protected function resolveDocument(int $id): Bill|Invoice|null
    {
        return Bill::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->first();
    }

    #[Override]
    protected function counterparty(Bill|Invoice $document): ?Organization
    {
        return $document->vendor;
    }
}
