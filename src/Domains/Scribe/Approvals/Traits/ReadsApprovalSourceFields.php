<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Traits;

use Kanvas\Scribe\Approvals\Enums\ApprovalAttachmentFieldEnum;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Reads back the source email and attachment stashed on an accounting record at intake.
 *
 * They travel out on the approval handler's result so the agent can reply on the original thread
 * without reading the record again.
 */
trait ReadsApprovalSourceFields
{
    /**
     * @return array<string, string|null>
     */
    private function sourceFields(Bill|Invoice $record): array
    {
        $messageId = (string) $record->get(ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID->value, '');

        return [
            'source_email_message_id' => $messageId !== '' ? $messageId : null,
            ...$this->attachmentFields($record),
        ];
    }

    /**
     * @return array{source_attachment_url: ?string, source_attachment_filename: ?string}
     */
    private function attachmentFields(Bill|Invoice $record): array
    {
        $fileEntity = $record->getFileByName(ApprovalAttachmentFieldEnum::INVOICE_PDF->value);

        if ($fileEntity !== null && $fileEntity->filesystem !== null) {
            return [
                'source_attachment_url' => (string) $fileEntity->filesystem->url,
                'source_attachment_filename' => (string) $fileEntity->filesystem->name,
            ];
        }

        // Legacy fallback: bills/invoices created before this fix still carry these as custom fields.
        $legacyUrl = (string) $record->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL->value, '');
        $legacyFilename = (string) $record->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME->value, '');

        return [
            'source_attachment_url' => $legacyUrl !== '' ? $legacyUrl : null,
            'source_attachment_filename' => $legacyFilename !== '' ? $legacyFilename : null,
        ];
    }
}
