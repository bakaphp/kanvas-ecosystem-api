<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

/**
 * Mercury transaction lifecycle. Only `sent` means money actually left or entered the account.
 *
 * We ingest ONLY settled transactions. A `pending` card auth can still be cancelled or settle at a
 * different amount, and booking it would put cash on the ledger that the bank never moved. `reversed`
 * arrives as its own separate transaction, so the original stays as-is and the reversal books itself.
 */
enum TransactionStatusEnum: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case REVERSED = 'reversed';
    case BLOCKED = 'blocked';

    public function isSettled(): bool
    {
        return $this === self::SENT;
    }
}
