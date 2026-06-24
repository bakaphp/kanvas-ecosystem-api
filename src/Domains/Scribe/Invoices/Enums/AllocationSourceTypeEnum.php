<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * What kind of credit drove this allocation. Drives the JE composer's posting decision.
 */
enum AllocationSourceTypeEnum: string
{
    case PAYMENT = 'payment';
    case CREDIT_NOTE = 'credit_note';
    case PREPAYMENT = 'prepayment';
    case OVERPAYMENT = 'overpayment';
    case WALLET = 'wallet';
    case MANUAL = 'manual';
}
