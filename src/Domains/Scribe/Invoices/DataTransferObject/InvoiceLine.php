<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Typed shape of one invoice line. Used by CreateInvoiceAction / UpdateInvoiceAction.
 */
class InvoiceLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unit_price_native,
        public readonly ?int $item_id = null,
        public readonly ?string $sku = null,
        public readonly ?int $sort_order = null,
        /** Overrides the account this line's JE debit/credit hits (e.g. a rebate's Control Acct#); null falls back to the document default. */
        public readonly ?int $account_id = null,
        public readonly ?float $discount_rate = null,
        public readonly float $discount_amount_native = 0.0,
        public readonly ?float $tax_rate = null,
        public readonly float $tax_amount_native = 0.0,
        public readonly ?array $tax_metadata = null,
        public readonly ?int $class_id = null,
        public readonly ?int $department_id = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function lineSubtotalNative(): float
    {
        return $this->quantity * $this->unit_price_native;
    }

    public function lineTotalNative(): float
    {
        return $this->lineSubtotalNative() - $this->discount_amount_native + $this->tax_amount_native;
    }
}
