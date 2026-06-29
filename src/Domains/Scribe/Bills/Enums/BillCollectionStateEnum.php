<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Enums;

/**
 * Two-axis state for Bills — independent of document_status. Drives aging dashboards on the AP side.
 *
 *   CURRENT       — not yet due (relative to due_date)
 *   OVERDUE       — past due, still payable
 *   DISPUTED      — operator flagged for dispute (manual)
 *   UNCOLLECTIBLE — written off, won't be paid (manual)
 *
 * Aging cron flips CURRENT ↔ OVERDUE. DISPUTED / UNCOLLECTIBLE are operator-only.
 */
enum BillCollectionStateEnum: string
{
    case CURRENT = 'current';
    case OVERDUE = 'overdue';
    case DISPUTED = 'disputed';
    case UNCOLLECTIBLE = 'uncollectible';
}
