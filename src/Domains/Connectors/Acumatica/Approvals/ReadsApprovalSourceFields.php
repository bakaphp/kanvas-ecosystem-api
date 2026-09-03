<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Illuminate\Database\Eloquent\Model;
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
        $fields = [];

        foreach ([
            'source_email_message_id' => ApprovalCustomFieldEnum::SOURCE_EMAIL_MESSAGE_ID,
            'source_attachment_url' => ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_URL,
            'source_attachment_filename' => ApprovalCustomFieldEnum::SOURCE_ATTACHMENT_FILENAME,
        ] as $key => $field) {
            $value = (string) $record->get($field->value, '');
            $fields[$key] = $value !== '' ? $value : null;
        }

        return $fields;
    }
}
