<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

/** Entity-level custom field keys used by the approval-queue flow. */
enum ApprovalCustomFieldEnum: string
{
    // The source email's message_id — lets a later approval reply in that same thread.
    case SOURCE_EMAIL_MESSAGE_ID = 'source_gmail_message_id';

    // The invoice PDF's URL — attached at approval time, once actually pushed to Acumatica.
    case SOURCE_ATTACHMENT_URL = 'source_attachment_url';
    case SOURCE_ATTACHMENT_FILENAME = 'source_attachment_filename';
}
