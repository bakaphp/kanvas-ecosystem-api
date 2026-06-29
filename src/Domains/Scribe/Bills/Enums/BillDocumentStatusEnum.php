<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Enums;

/**
 * Bill document lifecycle states.
 *
 *   DRAFT     — entered, not yet "we accept this bill is owed" (no JE)
 *   RECEIVED  — committed; vendor snapshot frozen + bill_number allocated + AP JE posted
 *   PAID      — fully paid via allocations; balance_due_native == 0
 *   VOIDED    — terminal; reversal JE posted, original JE marked reversed
 *
 * Note vs Invoices: one less status (no SENT, since vendors deliver to us).
 */
enum BillDocumentStatusEnum: string
{
    case DRAFT = 'draft';
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
