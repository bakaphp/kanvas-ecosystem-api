<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Support\Str;
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
     * @param  int|null  $topN Limit to top N organizations (groups rest into "Other"). Null = return all.
     * @param  list<string>|null  $includeTypes Only count registrations whose participant_type.slug is in this list.
     * @param  list<string>  $excludeTypes Exclude registrations with these participant_type slugs.
     * @return Collection<int, CompanyConcentration>
     */
    public static function forEventVersion(
        EventVersion $eventVersion,
        ?int $topN = null,
        ?array $includeTypes = null,
        array $excludeTypes = [],
    ): Collection {
        // Event DB: get participant_ids matching filters
        $participantIds = self::resolveParticipantIds($eventVersion, $includeTypes, $excludeTypes);

        if (empty($participantIds)) {
            return collect();
        }

        // Event DB: get people_ids from those participants
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

        // CRM DB: organizations for those people via pivot
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

        $mapped = $rows->map(fn ($row) => new CompanyConcentration(
            organization_id: $row->org_id !== null ? (int) $row->org_id : null,
            organization_name: (string) ($row->org_name ?? 'Unassigned'),
            count: (int) $row->cnt,
            percentage: $total > 0 ? round(((float) $row->cnt / (float) $total) * 100.0, 2) : 0.0,
        ));

        if ($topN !== null && $mapped->count() > $topN) {
            $top = $mapped->take($topN);
            $rest = $mapped->slice($topN);
            $otherCount = (int) $rest->sum('count');
            $otherPct = (float) $rest->sum('percentage');

            return $top->push(new CompanyConcentration(
                organization_id: null,
                organization_name: 'Other',
                count: $otherCount,
                percentage: round($otherPct, 2),
            ))->values();
        }

        return $mapped->values();
    }

    /**
     * @param  list<string>|null  $includeTypes
     * @param  list<string>  $excludeTypes
     * @return array<int, int>
     */
    protected static function resolveParticipantIds(
        EventVersion $eventVersion,
        ?array $includeTypes,
        array $excludeTypes,
    ): array {
        $query = DB::connection('event')
            ->table('event_version_participants')
            ->leftJoin('participant_types', 'event_version_participants.participant_type_id', '=', 'participant_types.id')
            ->where('event_version_participants.event_version_id', $eventVersion->getId())
            ->where('event_version_participants.is_deleted', 0);

        if ($includeTypes !== null || ! empty($excludeTypes)) {
            $rows = $query
                ->selectRaw('event_version_participants.participant_id, COALESCE(participant_types.name, ?) as type_name', ['Unassigned'])
                ->get();

            return $rows->filter(function ($row) use ($includeTypes, $excludeTypes) {
                $slug = Str::slug((string) $row->type_name);

                if (in_array($slug, $excludeTypes, true)) {
                    return false;
                }

                if ($includeTypes !== null && ! in_array($slug, $includeTypes, true)) {
                    return false;
                }

                return true;
            })
            ->pluck('participant_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        }

        return $query
            ->pluck('event_version_participants.participant_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
