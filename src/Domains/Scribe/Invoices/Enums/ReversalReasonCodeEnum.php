<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * Reversal taxonomy (Round-6 C7) — drives the dunning + NSF-reporting flows.
 */
enum ReversalReasonCodeEnum: string
{
    case BOUNCE = 'bounce';                  // bounced check / NSF — re-opens collection
    case CHARGEBACK = 'chargeback';           // card chargeback
    case CUSTOMER_DISPUTE = 'customer_dispute';
    case BANK_ERROR = 'bank_error';
    case FRAUD = 'fraud';
    case DUPLICATE = 'duplicate';             // we applied twice
    case ADMINISTRATIVE = 'administrative';
    case OTHER = 'other';

    public function reopensCollection(): bool
    {
        // Bounced checks + chargebacks unwind the "paid" state and the customer owes us again.
        return $this === self::BOUNCE || $this === self::CHARGEBACK || $this === self::CUSTOMER_DISPUTE;
    }
}
