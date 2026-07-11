<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Enums;

/**
 * Bill document lifecycle states.
 *
 *   DRAFT             — entered, not yet "we accept this bill is owed" (no JE)
 *   PENDING_APPROVAL  — proposed (e.g. by the AP-bill agent) and awaiting human sign-off (no JE)
 *   RECEIVED          — committed; vendor snapshot frozen + bill_number allocated + AP JE posted
 *   PAID              — fully paid via allocations; balance_due_native == 0
 *   VOIDED            — terminal; reversal JE posted, original JE marked reversed
 *
 * Note vs Invoices: one less status (no SENT, since vendors deliver to us).
 * The approval path (DRAFT → PENDING_APPROVAL → RECEIVED) sits alongside the direct DRAFT → RECEIVED
 * path — both are valid; the agent flow goes through approval so nothing hits the books unreviewed.
 */
enum BillDocumentStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case RECEIVED = 'received';
    case PAID = 'paid';
    case VOIDED = 'voided';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PAID, self::VOIDED => true,
            default => false,
        };
    }
}
