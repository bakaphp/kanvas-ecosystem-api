<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Contracts;

/**
 * One system we can ask "how many of each item do you think you have".
 *
 * Sources return their whole stock position rather than answering per item, because the report
 * has to work in both directions: what Yusen holds that the source disagrees about, *and* what
 * the source still shows stock for that Yusen never mentioned. A per-item lookup can only answer
 * the first half.
 *
 * Adding a system to the report is one implementation of this — the comparator never learns
 * about it.
 */
interface InventoryQuantitySource
{
    /**
     * Short identifier recorded as `source` on every discrepancy row (`kanvas`, `netsuite`, ...).
     */
    public function key(): string;

    /**
     * The source's full position, keyed by the same item identifier Yusen uses (the barcode).
     *
     * @return array<string, float>
     */
    public function quantities(): array;

    /**
     * Human-readable label for an item this source knows about but Yusen didn't send, used as the
     * description on a MISSING_IN_YUSEN row. Null when the source has no name to offer.
     */
    public function describe(string $item): ?string;
}
