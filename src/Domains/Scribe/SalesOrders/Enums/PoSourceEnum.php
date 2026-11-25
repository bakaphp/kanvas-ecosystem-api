<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Enums;

/**
 * What the customer committed with: their own purchase order, or our proforma returned signed and stamped.
 */
enum PoSourceEnum: string
{
    case CUSTOMER_PO = 'customer_po';
    case SIGNED_PROFORMA = 'signed_proforma';
}
