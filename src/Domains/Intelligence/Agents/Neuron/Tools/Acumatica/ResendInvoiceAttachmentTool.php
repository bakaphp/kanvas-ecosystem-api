<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;

/**
 * The AR mirror of resend_bill_attachment: create_ar_invoice DMs the approver with the invoice PDF
 * exactly the way create_ap_bill does, so a receivables approver can be left without it just as easily.
 */
#[AgentTool(name: 'Resend Invoice Attachment', category: 'accounting')]
class ResendInvoiceAttachmentTool extends AbstractResendApprovalAttachmentTool
{
    public function __construct()
    {
        parent::__construct(
            name: 'resend_invoice_attachment',
            description: 'Re-sends a pending AR invoice\'s PDF to its configured approver(s) on Slack. Use this '
                . 'when an approver says they did not receive the attachment with their approval request. Only '
                . 'works if an invoice PDF was captured when the invoice was created — reports plainly when '
                . 'there is nothing on file to resend, rather than failing silently.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id): array
    {
        return $this->resendAttachment($invoice_id);
    }

    #[Override]
    protected function noun(): string
    {
        return 'invoice';
    }

    #[Override]
    protected function resolveDocument(int $id): Bill|Invoice|null
    {
        return Invoice::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->first();
    }

    #[Override]
    protected function counterparty(Bill|Invoice $document): ?Organization
    {
        return $document->customer;
    }
}
