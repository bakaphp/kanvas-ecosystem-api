<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Exceptions;

use Kanvas\Scribe\Ledger\Exceptions\InvalidDocumentTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

class InvalidPurchaseOrderTransitionException extends InvalidDocumentTransitionException
{
    public static function notIssuedByCompany(PurchaseOrder $order, string $change): self
    {
        return new self("Purchase order {$order->order_number} comes from an external system and cannot be {$change} here.");
    }
}
