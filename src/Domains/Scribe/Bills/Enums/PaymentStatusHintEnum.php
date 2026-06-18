<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Enums;

/**
 * Operator-facing hint, NOT a GL-state column. Captures what the PDF-ingest LLM thinks about
 * the Bill's payment state so the operator UI can show "Mark as already paid?" suggestions
 * without touching the actual `document_status` lifecycle.
 *
 * The authoritative payment state stays in `document_status` (DRAFT → RECEIVED → PAID) — this
 * column is just a hint extracted from the document at ingest time and never auto-advances any
 * state machine. It's metadata for the human, not the bookkeeping.
 */
enum PaymentStatusHintEnum: string
{
    /** PDF shows the bill has already been paid (PAID stamp, receipt section, payment date, etc.). */
    case PAID = 'paid';

    /** PDF shows the bill is outstanding — future due date, no payment evidence. */
    case UNPAID = 'unpaid';

    /** LLM couldn't determine or didn't extract — default for non-PDF-ingested bills. */
    case UNKNOWN = 'unknown';
}
