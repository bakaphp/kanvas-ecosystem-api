<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Enums;

/**
 * Sales receipts have a simpler lifecycle than invoices — no draft, no AR cycle, just recorded or voided.
 * The customer paid at the moment of sale; the receipt is the post-facto record.
 *
 *   RECORDED  → VOIDED   (only valid transition)
 *   VOIDED    → (terminal)
 *
 * @see plan §11.3 — Sales Receipts worked example
 */
enum SalesReceiptStatusEnum: string
{
    case RECORDED = 'recorded';
    case VOIDED = 'voided';

    public function isTerminal(): bool
    {
        return $this === self::VOIDED;
    }
}
