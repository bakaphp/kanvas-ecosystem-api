<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Approvals\ReadsApprovalSourceFields;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Shared body for the AP/AR "the approver says the PDF never arrived — send it again" tools. Both
 * read the same attachment slot, resolve the same approver list and post the same Slack DM; only the
 * document and the counterparty organization differ.
 *
 * Naming (LLM param, result keys, reasons, prose) all derives from noun() so 'bill'/'invoice' can't
 * drift between the schema and the response — same shape as AbstractApplyAcumaticaPaymentTool.
 */
abstract class AbstractResendApprovalAttachmentTool extends Tool
{
    use HasKanvasContext;
    use ReadsApprovalSourceFields;

    /** 'bill' | 'invoice' — drives the {noun}_id param, the result keys, and the messages. */
    abstract protected function noun(): string;

    abstract protected function resolveDocument(int $id): Bill|Invoice|null;

    /** The vendor (AP) or customer (AR) whose configured approver receives the DM. */
    abstract protected function counterparty(Bill|Invoice $document): ?Organization;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        $noun = $this->noun();

        return [
            new ToolProperty(
                name: $noun . '_id',
                type: PropertyType::INTEGER,
                description: "The Kanvas {$noun} id, from the create tool or the approver's own message. "
                    . 'Never guess it.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resendAttachment(int $id): array
    {
        $noun = $this->noun();
        $document = $this->resolveDocument($id);

        if ($document === null) {
            return [
                'resent' => false,
                'reason' => $noun . '_not_found',
                'message' => "No {$noun} with id {$id} for this app/company.",
            ];
        }

        $sourceFields = $this->sourceFields($document);
        $attachmentUrl = $sourceFields['source_attachment_url'];

        if ($attachmentUrl === null) {
            return [
                'resent' => false,
                'reason' => 'no_attachment_on_file',
                'message' => ucfirst($noun) . " {$id} has no invoice PDF on file — there is nothing to resend.",
            ];
        }

        $counterparty = $this->counterparty($document);
        $approverEmails = $counterparty !== null
            ? ResolveApproverEmailAction::resolveForOrganization($counterparty)
            : [];

        if ($approverEmails === []) {
            return [
                'resent' => false,
                'reason' => 'no_approver_configured',
                'message' => 'No approver configured for ' . $noun . " {$id}'s "
                    . ($noun === 'bill' ? 'vendor' : 'customer') . ' — there is nobody to send it to.',
            ];
        }

        NotifyApproverAction::notifyAll(
            approverEmails: $approverEmails,
            app: $this->app,
            text: "Here is the invoice for {$noun} {$id}, resent on request.",
            attachmentUrl: $attachmentUrl,
            attachmentFilename: $sourceFields['source_attachment_filename'],
        );

        return [
            'resent' => true,
            $noun . '_id' => $id,
            'sent_to' => $approverEmails,
        ];
    }
}
