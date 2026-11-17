<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Baka\Support\Str;

/**
 * One `sales` row with its live children, as BrushCrazy's Eloquent relations would load them:
 * soft-deleted items, taxes, discounts and payments are already left out, because Sale::total()
 * never sees them either.
 */
final readonly class SaleRows
{
    /**
     * @param  array<int, object>  $items
     * @param  array<int, object>  $taxes
     * @param  array<int, object>  $discounts
     * @param  array<int, object>  $payments
     */
    public function __construct(
        public object $sale,
        public array $items = [],
        public array $taxes = [],
        public array $discounts = [],
        public array $payments = [],
    ) {
    }

    public function id(): int
    {
        return (int) $this->sale->id;
    }

    /** A partial-refund mirror (`original_sale` set) is netted into its parent, never imported. */
    public function isMirror(): bool
    {
        return ($this->sale->original_sale ?? null) !== null;
    }

    /**
     * Set on a sale the Kanvas site pushed: that sale is the Kanvas Order with this uuid, not one
     * the mirror builds. Absent on every row read before BrushCrazy added the column.
     */
    public function kanvasOrderUuid(): ?string
    {
        return Str::trimmedStringOrNull($this->sale->kanvas_order_uuid ?? null);
    }
}
