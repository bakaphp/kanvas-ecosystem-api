<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Maintains the active_leads_count counter cache on People. "Counted" mirrors
 * Lead::isOpen() (status < 2) — not leads_status_id, which the frontend and
 * other Lead helpers (isActive(), close()/open()) define inconsistently.
 */
class LeadActiveLeadsCounterObserver
{
    public function created(Lead $lead): void
    {
        if ($lead->people_id && $this->isCounted($lead)) {
            $this->adjust((int) $lead->people_id, +1);
        }
    }

    public function updated(Lead $lead): void
    {
        $originalPeopleId = $lead->getOriginal('people_id') ? (int) $lead->getOriginal('people_id') : null;
        $currentPeopleId = $lead->people_id ? (int) $lead->people_id : null;
        $wasCounted = $this->wasCounted($lead);
        $isCounted = $this->isCounted($lead);

        if ($originalPeopleId === $currentPeopleId) {
            if ($wasCounted === $isCounted) {
                return;
            }

            if ($currentPeopleId !== null) {
                $this->adjust($currentPeopleId, $isCounted ? +1 : -1);
            }

            return;
        }

        if ($wasCounted && $originalPeopleId !== null) {
            $this->adjust($originalPeopleId, -1);
        }

        if ($isCounted && $currentPeopleId !== null) {
            $this->adjust($currentPeopleId, +1);
        }
    }

    public function deleted(Lead $lead): void
    {
        // Hard delete path — Lead has no SoftDeletingScope, so a real ->delete()
        // (tests, admin cleanup) removes the row. The normal soft-delete path
        // (Lead::softDelete()) goes through updated() instead.
        if ($lead->people_id && $this->isCounted($lead)) {
            $this->adjust((int) $lead->people_id, -1);
        }
    }

    private function isCounted(Lead $lead): bool
    {
        return $lead->isOpen() && ! (bool) $lead->is_deleted;
    }

    private function wasCounted(Lead $lead): bool
    {
        return (int) ($lead->getOriginal('status') ?? 0) < 2
            && ! (bool) $lead->getOriginal('is_deleted');
    }

    private function adjust(int $peopleId, int $delta): void
    {
        $query = People::where('id', $peopleId);

        if ($delta < 0) {
            $query->where('active_leads_count', '>', 0);
        }

        $query->increment('active_leads_count', $delta);
    }
}
