<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Contracts;

interface DiscoveryIndexAdapter
{
    public const int STALE_ID_LIMIT = 50;

    /**
     * Brings the app's product index up to what discovery reads, without removing or reordering
     * anything already there. `refusal` is set when a change was withheld on purpose and `proposed`
     * then carries what would have been written.
     *
     * @return array{index: string, changes: list<string>, written: bool, refusal: ?string, proposed: list<string>}
     */
    public function ensureFields(bool $dryRun, bool $fromDefault): array;

    /**
     * Both counts are scoped to this app because apps can share one index. `stale_ids` only holds
     * records that carry an older version: a record missing the field entirely cannot be filtered
     * on, so it shows up only as the gap between `total` and `migrated`.
     *
     * @return array{total: int, migrated: int, stale_ids: list<int>}
     */
    public function coverage(int $version): array;
}
