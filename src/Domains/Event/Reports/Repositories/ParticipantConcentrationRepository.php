<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\DataTransferObject\CompanyConcentration;

class ParticipantConcentrationRepository
{
    /**
     * Breakdown of registrations by client Organization for a given EventVersion.
     *
     * @return Collection<int, CompanyConcentration>
     */
    public static function forEventVersion(EventVersion $eventVersion): Collection
    {
        // Event DB: get all participant_ids for this event version
        $participantIds = DB::connection('event')
            ->table('event_version_participants')
            ->where('event_version_id', $eventVersion->getId())
            ->where('is_deleted', 0)
            ->pluck('participant_id')
            ->filter()
            ->unique()
            ->all();

        if (empty($participantIds)) {
            return collect();
        }

        // Event DB: get all people_ids from participants
        $peopleIds = DB::connection('event')
            ->table('participants')
            ->whereIn('id', $participantIds)
            ->pluck('people_id')
            ->filter()
            ->unique()
            ->all();

        if (empty($peopleIds)) {
            return collect();
        }

        // CRM DB: get organizations for those people via the pivot
        $rows = DB::connection('crm')
            ->table('organizations_peoples')
            ->leftJoin('organizations', 'organizations_peoples.organizations_id', '=', 'organizations.id')
            ->whereIn('organizations_peoples.peoples_id', $peopleIds)
            ->where(function (Builder $q) {
                $q->whereNull('organizations.is_deleted')
                  ->orWhere('organizations.is_deleted', 0);
            })
            ->selectRaw('organizations.id as org_id, organizations.name as org_name, COUNT(DISTINCT organizations_peoples.peoples_id) as cnt')
            ->groupBy('org_id', 'org_name')
            ->orderByDesc('cnt')
            ->get();

        $total = (int) $rows->sum('cnt');

        return $rows->map(fn ($row) => new CompanyConcentration(
            organization_id: $row->org_id !== null ? (int) $row->org_id : null,
            organization_name: (string) ($row->org_name ?? 'Unassigned'),
            count: (int) $row->cnt,
            percentage: $total > 0 ? round(((float) $row->cnt / (float) $total) * 100.0, 2) : 0.0,
        ));
    }
}
