<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Approvals\Enums\ApprovalCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Stashes the originating email/attachment on a bill/invoice, for ResolveApprovalAction to use later. */
trait StoresApprovalSourceFields
{
    private function storeApprovalSourceFields(
        Bill|Invoice $record,
        ?string $messageId,
        ?string $attachmentUrl,
        ?string $attachmentFilename,
    ): void {
        if ($messageId !== null && trim($messageId) !== '') {
            $record->set(ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID->value, trim($messageId));
        }

        if ($attachmentUrl !== null && trim($attachmentUrl) !== '') {
            $record->set(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL->value, trim($attachmentUrl));
        }

        if ($attachmentFilename !== null && trim($attachmentFilename) !== '') {
            $record->set(ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME->value, trim($attachmentFilename));
        }
    }
}
