<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * Collections-level state — where the AR-aging story stands (computed by EvaluateInvoiceAgingJob).
 *
 *   current → overdue → disputed → uncollectible
 *          ↘ (paid or voided clears collection_state to NULL)
 *
 * NULL for non-issued documents (drafts, voided) and for paid invoices.
 *
 * @see plan §7.1 — two-orthogonal-axes invoice status
 * @see plan §7.2 — EvaluateInvoiceAgingJob updates this daily
 */
enum InvoiceCollectionStateEnum: string
{
    case CURRENT = 'current';
    case OVERDUE = 'overdue';
    case DISPUTED = 'disputed';
    case UNCOLLECTIBLE = 'uncollectible';
}
