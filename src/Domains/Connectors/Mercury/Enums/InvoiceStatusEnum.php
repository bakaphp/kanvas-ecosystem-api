<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

/**
 * Mercury AR invoice lifecycle (`GET /ar/invoices`).
 *
 * Note this says nothing about OUR books. A Mercury invoice flipping to Paid means Mercury collected the
 * money — the cash then shows up as a deposit in the bank feed, and THAT is what settles the Scribe invoice.
 * We sync this status for visibility (and to stop chasing someone who already paid), never to post a JE.
 */
enum InvoiceStatusEnum: string
{
    case UNPAID = 'Unpaid';
    case PROCESSING = 'Processing';
    case PAID = 'Paid';
    case CANCELLED = 'Cancelled';

    /** Money is in flight but not landed. Nothing is settled until the deposit hits the account. */
    public function isCollected(): bool
    {
        return $this === self::PAID;
    }

    public function isOpen(): bool
    {
        return $this === self::UNPAID || $this === self::PROCESSING;
    }
}
