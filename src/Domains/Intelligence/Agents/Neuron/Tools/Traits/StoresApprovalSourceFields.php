<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Approvals\Enums\ApprovalAttachmentFieldEnum;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Stashes the originating email/attachment on a bill/invoice, for ReadsApprovalSourceFields to use later. */
trait StoresApprovalSourceFields
{
    private function storeApprovalSourceFields(
        Bill|Invoice $record,
        ?string $messageId,
        ?int $attachmentFilesystemId,
    ): void {
        if ($messageId !== null && trim($messageId) !== '') {
            $record->set(ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID->value, trim($messageId));
        }

        if ($attachmentFilesystemId === null) {
            return;
        }

        // Third-party files (the invoice PDF) are attached to the entity via Kanvas Filesystem,
        // never stored as a custom field — download_attachment already created this row.
        $filesystem = Filesystem::query()
            ->fromApp($this->app)
            ->where('companies_id', $this->company->getId())
            ->where('id', $attachmentFilesystemId)
            ->first();

        if ($filesystem !== null) {
            $record->addFile($filesystem, ApprovalAttachmentFieldEnum::INVOICE_PDF->value);
        }
    }
}
