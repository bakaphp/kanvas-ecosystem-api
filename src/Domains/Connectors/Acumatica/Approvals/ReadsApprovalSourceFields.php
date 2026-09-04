<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Scribe\Approvals\Enums\ApprovalAttachmentFieldEnum;
use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;

/**
 * Reads back the source email and attachment that Apex/Arc stashed on the record at intake.
 *
 * The agent needs these to attach the original PDF and reply on the original thread, and it can only
 * do that once the record is actually pushed — so they travel out on the handler's result rather than
 * being read again later.
 */
trait ReadsApprovalSourceFields
{
    /**
     * @return array<string, string|null>
     */
    private function sourceFields(Model $record): array
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
    private function attachmentFields(Model $record): array
    {
        $fileEntity = $record->getFileByName(ApprovalAttachmentFieldEnum::INVOICE_PDF->value);

        if ($fileEntity !== null && $fileEntity->filesystem !== null) {
            return [
                'source_attachment_url' => (string) $fileEntity->filesystem->url,
                'source_attachment_filename' => (string) $fileEntity->filesystem->name,
            ];
        }

        // Legacy bills/invoices created before the Filesystem-attachment fix still carry these as
        // custom fields — read them back so old pending approvals keep working.
        $legacyUrl = (string) $record->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL->value, '');
        $legacyFilename = (string) $record->get(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME->value, '');

        return [
            'source_attachment_url' => $legacyUrl !== '' ? $legacyUrl : null,
            'source_attachment_filename' => $legacyFilename !== '' ? $legacyFilename : null,
        ];
    }
}
