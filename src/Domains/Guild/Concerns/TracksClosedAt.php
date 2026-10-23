<?php

declare(strict_types=1);

namespace Kanvas\Guild\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Models\LeadStatus;

/**
 * `closed_at` is the moment the named status crossed from open to closed; it is cleared again
 * when the record is reopened. Nothing before 2026-10-23 was stamped and there is no backfill:
 * no table recorded a status change, so any date put on an older row would be a guess.
 *
 * Lead keeps its status on `leads_status_id` and Deal on `status_id`, which is why the column
 * name is passed in. Open state comes from the status row's `is_open` (LeadStatus::isOpenId),
 * shared by both models.
 */
trait TracksClosedAt
{
    /**
     * A record created straight into a closed status (a DMS import of a sold unit) is closed from
     * the start, so its `closed_at` is its creation time.
     */
    public function stampClosedAtOnCreate(string $statusColumn): void
    {
        $statusId = (int) $this->getAttribute($statusColumn);

        if ($this->closed_at === null && ! LeadStatus::isOpenId($statusId, $this->company)) {
            $this->closed_at = Carbon::now();
        }
    }

    /**
     * Call from the `updating` hook. Returns the transition when the status crossed the open/closed
     * line in either direction (the ledger payload), null when it did not.
     *
     * @return array{from_status_id: int, to_status_id: int, from_state: string, to_state: string}|null
     */
    public function syncClosedAtFromStatusChange(string $statusColumn): ?array
    {
        if (! $this->isDirty($statusColumn)) {
            return null;
        }

        $fromId = (int) $this->getOriginal($statusColumn);
        $toId = (int) $this->getAttribute($statusColumn);
        // A row with no status yet has never been closed; treat the missing original as open so
        // its first real status still stamps the column.
        $wasOpen = $fromId === 0 || LeadStatus::isOpenId($fromId, $this->company);
        $isOpen = LeadStatus::isOpenId($toId, $this->company);

        if ($wasOpen === $isOpen) {
            return null;
        }

        $this->closed_at = $isOpen ? null : Carbon::now();

        return [
            'from_status_id' => $fromId,
            'to_status_id' => $toId,
            'from_state' => $wasOpen ? 'open' : 'closed',
            'to_state' => $isOpen ? 'open' : 'closed',
        ];
    }
}
