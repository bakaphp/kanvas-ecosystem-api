<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * Document-level status — what the invoice *is* (mutually exclusive, transitions managed by InvoiceStateMachineService).
 *
 *   draft → issued → sent → paid
 *                 ↘ voided  (only from issued/sent — voiding a paid invoice = credit note instead)
 *
 * Distinct from collection_state (the AR-aging lens — current/overdue/disputed/uncollectible).
 *
 * @see plan §7.1 — two-orthogonal-axes invoice status
 */
enum InvoiceDocumentStatusEnum: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case SENT = 'sent';
    case PAID = 'paid';
    case VOIDED = 'voided';

    public function isTerminal(): bool
    {
        return $this === self::PAID || $this === self::VOIDED;
    }

    public function isMutable(): bool
    {
        // Only drafts can be edited freely. Issued/sent require AmendInvoiceAction (post-issue mutator).
        return $this === self::DRAFT;
    }

    public function impactsBooks(): bool
    {
        // Drafts haven't posted a JE — no impact on the books.
        return $this !== self::DRAFT;
    }
}
