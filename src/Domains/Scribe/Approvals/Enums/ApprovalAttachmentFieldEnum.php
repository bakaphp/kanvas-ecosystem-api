<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

/** Kanvas Filesystem field_name the invoice PDF is attached under (via HasFilesystemTrait), not a custom field. */
enum ApprovalAttachmentFieldEnum: string
{
    case INVOICE_PDF = 'source_invoice_pdf';
}
