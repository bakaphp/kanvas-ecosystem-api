<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Stashes the originating email/attachment on a bill/invoice, for ReadsApprovalSourceFields to use later. */
trait StoresApprovalSourceFields
{
    use AttachesFileToDocumentForTool;

    private function storeApprovalSourceFields(
        Bill|Invoice $record,
        ?string $messageId,
        ?int $attachmentFilesystemId,
    ): void {
        $messageId = Str::trimToNull($messageId);

        if ($messageId !== null) {
            $record->set(ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID->value, $messageId);
        }

        if ($attachmentFilesystemId === null) {
            return;
        }

        // Third-party files (the invoice PDF) are attached to the entity via Kanvas Filesystem —
        // the same mechanism every other entity in Kanvas uses — never a custom field. A file id
        // that resolves to nothing is left for the caller to notice: it re-reads sourceFields() and
        // reports the missing attachment to the model rather than failing the whole bill/invoice.
        $this->attachFileToDocument(document: $record, filesystemId: $attachmentFilesystemId);
    }
}
