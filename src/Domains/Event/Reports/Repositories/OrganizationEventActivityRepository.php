<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Reports\DataTransferObject\OrganizationEventActivity;
use Kanvas\Event\Reports\Enums\OrgActivityFilterEnum;
use Kanvas\Event\Reports\Enums\OrgActivityOrderEnum;

class OrganizationEventActivityRepository
{
    /**
     * Activity breakdown by client Organization in a time window.
     *
     * Joins span the `event` DB (participations + dates) and the `crm` DB
     * (organizations + organizations_peoples pivot). Aggregation is done in PHP
     * because the two sides live in different connections.
     *
     * @param  list<string>|null  $includeParticipantTypes Slug whitelist applied to participant_types.name
     * @param  list<string>  $excludeParticipantTypes Slug blacklist
     */
    public static function query(
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        OrgActivityFilterEnum $activity = OrgActivityFilterEnum::ALL,
        ?int $minCount = null,
        ?int $maxCount = null,
        ?int $eventTypeId = null,
        ?int $eventCategoryId = null,
        ?array $includeParticipantTypes = null,
        array $excludeParticipantTypes = [],
        ?int $topN = null,
        OrgActivityOrderEnum $orderBy = OrgActivityOrderEnum::COUNT_DESC,
    ): Collection {
        $peopleStats = self::buildPeopleStats(
            app: $app,
            company: $company,
            fromDate: $fromDate,
            toDate: $toDate,
            eventTypeId: $eventTypeId,
            eventCategoryId: $eventCategoryId,
            includeParticipantTypes: $includeParticipantTypes,
            excludeParticipantTypes: $excludeParticipantTypes,
        );

        $orgs = self::loadOrganizationsWithPeople($app, $company);

        $rows = self::aggregatePerOrganization($orgs, $peopleStats, $fromDate);

        $rows = self::applyActivityFilter($rows, $activity);

        if ($minCount !== null) {
            $rows = $rows->filter(fn (OrganizationEventActivity $r) => $r->count >= $minCount);
        }
        if ($maxCount !== null) {
            $rows = $rows->filter(fn (OrganizationEventActivity $r) => $r->count <= $maxCount);
        }

        $rows = self::applyOrder($rows, $orderBy);

        if ($topN !== null) {
            $rows = $rows->take($topN);
        }

        return $rows->values();
    }

    /**
     * For every people_id of the tenant that participated in any event,
     * build a stats row spanning both the requested window and "ever".
     *
     * @param  list<string>|null  $includeParticipantTypes
     * @param  list<string>  $excludeParticipantTypes
     * @return array<int, array{count_in_window:int,first_in_window:?string,last_in_window:?string,first_ever:?string,last_ever:?string}>
     */
    protected static function buildPeopleStats(
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        ?int $eventTypeId,
        ?int $eventCategoryId,
        ?array $includeParticipantTypes,
        array $excludeParticipantTypes,
    ): array {
        // One row per participation. event_date = MIN(event_date) of the version
        // (canonical date for the registration), matching OpenEventsTrackingRepository.
        $versionDates = DB::connection('event')
            ->table('event_version_dates')
            ->where('is_deleted', 0)
            ->selectRaw('event_version_id, MIN(event_date) as event_date')
            ->groupBy('event_version_id');

        $query = DB::connection('event')
            ->table('event_version_participants as evp')
            ->joinSub($versionDates, 'evd', 'evd.event_version_id', '=', 'evp.event_version_id')
            ->join('participants as p', function ($join) use ($app, $company) {
                $join->on('p.id', '=', 'evp.participant_id')
                    ->where('p.is_deleted', 0)
                    ->where('p.apps_id', $app->getId())
                    ->where('p.companies_id', $company->getId());
            })
            ->join('event_versions as ev', function ($join) {
                $join->on('ev.id', '=', 'evp.event_version_id')
                    ->where('ev.is_deleted', 0);
            })
            ->leftJoin('events as e', 'e.id', '=', 'ev.event_id')
            ->leftJoin('participant_types as pt', 'pt.id', '=', 'evp.participant_type_id')
            ->where('evp.is_deleted', 0)
            ->whereNotNull('p.people_id');

        if ($eventTypeId !== null) {
            $query->where('e.event_type_id', $eventTypeId);
        }
        if ($eventCategoryId !== null) {
            $query->where('e.event_category_id', $eventCategoryId);
        }

        $rows = $query
            ->select([
                'p.people_id',
                'evd.event_date',
                DB::raw('COALESCE(pt.name, "") as participant_type_name'),
            ])
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $slug = $row->participant_type_name !== '' ? Str::slug((string) $row->participant_type_name) : '';
            if (! empty($excludeParticipantTypes) && in_array($slug, $excludeParticipantTypes, true)) {
                continue;
            }
            if ($includeParticipantTypes !== null && ! in_array($slug, $includeParticipantTypes, true)) {
                continue;
            }

            $peopleId = (int) $row->people_id;
            $date = $row->event_date !== null ? (string) $row->event_date : null;

            if (! isset($stats[$peopleId])) {
                $stats[$peopleId] = [
                    'count_in_window' => 0,
                    'first_in_window' => null,
                    'last_in_window' => null,
                    'first_ever' => null,
                    'last_ever' => null,
                ];
            }

            if ($date !== null) {
                $stats[$peopleId]['first_ever'] = self::minDate($stats[$peopleId]['first_ever'], $date);
                $stats[$peopleId]['last_ever'] = self::maxDate($stats[$peopleId]['last_ever'], $date);
            }

            if (self::dateInWindow($date, $fromDate, $toDate)) {
                $stats[$peopleId]['count_in_window']++;
                if ($date !== null) {
                    $stats[$peopleId]['first_in_window'] = self::minDate($stats[$peopleId]['first_in_window'], $date);
                    $stats[$peopleId]['last_in_window'] = self::maxDate($stats[$peopleId]['last_in_window'], $date);
                }
            }
        }

        return $stats;
    }

    /**
     * Load every non-deleted organization of the tenant with the people_ids
     * linked via the pivot.
     *
     * @return array<int, array{id:int,name:string,people_ids:array<int,int>}>
     */
    protected static function loadOrganizationsWithPeople(
        AppInterface $app,
        CompanyInterface $company,
    ): array {
        $rows = DB::connection('crm')
            ->table('organizations as o')
            ->leftJoin('organizations_peoples as op', function ($join) {
                $join->on('op.organizations_id', '=', 'o.id');
            })
            ->where('o.apps_id', $app->getId())
            ->where('o.companies_id', $company->getId())
            ->where(function (Builder $q) {
                $q->whereNull('o.is_deleted')
                  ->orWhere('o.is_deleted', 0);
            })
            ->select(['o.id as org_id', 'o.name as org_name', 'op.peoples_id'])
            ->get();

        $orgs = [];
        foreach ($rows as $row) {
            $orgId = (int) $row->org_id;
            if (! isset($orgs[$orgId])) {
                $orgs[$orgId] = [
                    'id' => $orgId,
                    'name' => (string) ($row->org_name ?? ''),
                    'people_ids' => [],
                ];
            }
            if ($row->peoples_id !== null) {
                $orgs[$orgId]['people_ids'][] = (int) $row->peoples_id;
            }
        }

        return $orgs;
    }

    /**
     * @param  array<int, array{id:int,name:string,people_ids:array<int,int>}>  $orgs
     * @param  array<int, array{count_in_window:int,first_in_window:?string,last_in_window:?string,first_ever:?string,last_ever:?string}>  $peopleStats
     * @return Collection<int, OrganizationEventActivity>
     */
    protected static function aggregatePerOrganization(
        array $orgs,
        array $peopleStats,
        ?Carbon $fromDate,
    ): Collection {
        $rows = collect();
        $fromStr = $fromDate?->toDateString();

        foreach ($orgs as $org) {
            $count = 0;
            $uniquePeople = 0;
            $firstEver = null;
            $lastEver = null;
            $hadPrior = false;

            foreach (array_unique($org['people_ids']) as $peopleId) {
                if (! isset($peopleStats[$peopleId])) {
                    continue;
                }
                $s = $peopleStats[$peopleId];

                $count += $s['count_in_window'];
                if ($s['count_in_window'] > 0) {
                    $uniquePeople++;
                }
                $firstEver = self::minDate($firstEver, $s['first_ever']);
                $lastEver = self::maxDate($lastEver, $s['last_ever']);

                if (! $hadPrior && $fromStr !== null && $s['first_ever'] !== null && $s['first_ever'] < $fromStr) {
                    $hadPrior = true;
                }
            }

            $rows->push(new OrganizationEventActivity(
                organization_id: $org['id'],
                organization_name: $org['name'] !== '' ? $org['name'] : 'Unassigned',
                count: $count,
                unique_people_count: $uniquePeople,
                first_event_date: $firstEver,
                last_event_date: $lastEver,
                had_prior_activity: $hadPrior,
            ));
        }

        return $rows;
    }

    /**
     * @param  Collection<int, OrganizationEventActivity>  $rows
     * @return Collection<int, OrganizationEventActivity>
     */
    protected static function applyActivityFilter(Collection $rows, OrgActivityFilterEnum $activity): Collection
    {
        return match ($activity) {
            OrgActivityFilterEnum::ALL => $rows,
            OrgActivityFilterEnum::ACTIVE => $rows->filter(fn (OrganizationEventActivity $r) => $r->count > 0),
            OrgActivityFilterEnum::INACTIVE => $rows->filter(fn (OrganizationEventActivity $r) => $r->count === 0),
            // Had activity before the window opened but none inside it.
            OrgActivityFilterEnum::LAPSED => $rows->filter(
                fn (OrganizationEventActivity $r) => $r->count === 0 && $r->had_prior_activity,
            ),
            // First-ever participation falls inside the window (no prior activity).
            OrgActivityFilterEnum::NEW => $rows->filter(
                fn (OrganizationEventActivity $r) => $r->count > 0 && ! $r->had_prior_activity,
            ),
        };
    }

    /**
     * @param  Collection<int, OrganizationEventActivity>  $rows
     * @return Collection<int, OrganizationEventActivity>
     */
    protected static function applyOrder(Collection $rows, OrgActivityOrderEnum $orderBy): Collection
    {
        return match ($orderBy) {
            OrgActivityOrderEnum::COUNT_DESC => $rows->sortByDesc(fn ($r) => $r->count),
            OrgActivityOrderEnum::COUNT_ASC => $rows->sortBy(fn ($r) => $r->count),
            // Nulls sort last for last-date desc, first for first-date asc.
            OrgActivityOrderEnum::LAST_DATE_DESC => $rows->sortByDesc(fn ($r) => $r->last_event_date ?? ''),
            OrgActivityOrderEnum::FIRST_DATE_ASC => $rows->sortBy(fn ($r) => $r->first_event_date ?? '9999-12-31'),
            OrgActivityOrderEnum::NAME_ASC => $rows->sortBy(fn ($r) => mb_strtolower($r->organization_name)),
        };
    }

    protected static function dateInWindow(?string $date, ?Carbon $from, ?Carbon $to): bool
    {
        if ($date === null) {
            return false;
        }
        if ($from !== null && $date < $from->toDateString()) {
            return false;
        }
        if ($to !== null && $date > $to->toDateString()) {
            return false;
        }

        return true;
    }

    protected static function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a < $b ? $a : $b;
    }

    protected static function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a > $b ? $a : $b;
    }
}
