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
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;

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

        $rows = self::aggregatePerOrganization(
            $orgs,
            $peopleStats,
            $fromDate,
            $toDate
        );

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
     * @return array<int, array{count_in_window:int,first_in_window:?string,last_in_window:?string,first_ever:?string,last_ever:?string,count_last_year:int,years:array<int,int>}>
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
        $query = self::participations($app->getId(), $company->getId());

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
        $lastYearFrom = Carbon::now()->subYear()->toDateString();
        $today = Carbon::now()->toDateString();

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
                    'count_last_year' => 0,
                    'years' => [],
                ];
            }

            if ($date !== null && $date >= $lastYearFrom && $date <= $today) {
                $stats[$peopleId]['count_last_year']++;
            }

            if ($date !== null) {
                $stats[$peopleId]['first_ever'] = self::minDate($stats[$peopleId]['first_ever'], $date);
                $stats[$peopleId]['last_ever'] = self::maxDate($stats[$peopleId]['last_ever'], $date);
            }

            if (self::dateInWindow($date, $fromDate, $toDate)) {
                $stats[$peopleId]['count_in_window']++;

                if ($date !== null) {
                    $year = (int) substr($date, 0, 4);
                    $stats[$peopleId]['years'][$year] = ($stats[$peopleId]['years'][$year] ?? 0) + 1;
                }

                if ($date !== null) {
                    $stats[$peopleId]['first_in_window'] = self::minDate($stats[$peopleId]['first_in_window'], $date);
                    $stats[$peopleId]['last_in_window'] = self::maxDate($stats[$peopleId]['last_in_window'], $date);
                }
            }
        }

        return $stats;
    }

    /**
     * One client company's participation history: every event version its people registered
     * for, newest first, with who went.
     *
     * The company's people come from the `crm` pivot and their registrations from the `event`
     * database, so the two are resolved in separate queries and joined in PHP.
     *
     * @return list<array{event_version_id:int, event_name:string, version_name:string, event_date:?string, registrations:int, participants:int, people:list<array{people_id:int, name:string, registration_type:?string}>}>
     */
    public static function historyFor(
        Organization $organization,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        int $limit = 100
    ): array {
        $peopleIds = DB::connection('crm')
            ->table('organizations_peoples')
            ->where('organizations_id', $organization->getId())
            ->pluck('peoples_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($peopleIds === []) {
            return [];
        }

        $rows = self::participations((int) $organization->apps_id, (int) $organization->companies_id)
            ->whereIn('p.people_id', $peopleIds)
            ->select([
                'ev.id as event_version_id',
                'e.name as event_name',
                'ev.name as version_name',
                'evd.event_date',
                'p.people_id',
                'pt.name as registration_type',
            ])
            ->get()
            ->filter(fn ($row) => $fromDate === null && $toDate === null
                || self::dateInWindow($row->event_date !== null ? (string) $row->event_date : null, $fromDate, $toDate));

        $names = People::query()
            ->whereIn('id', $rows->pluck('people_id')->unique()->all())
            ->get(['id', 'firstname', 'middlename', 'lastname'])
            ->mapWithKeys(fn (People $people) => [$people->getId() => $people->getName()]);

        return $rows
            ->groupBy('event_version_id')
            ->map(fn (Collection $registrations) => [
                'event_version_id' => (int) $registrations->first()->event_version_id,
                'event_name' => (string) ($registrations->first()->event_name ?? ''),
                'version_name' => (string) ($registrations->first()->version_name ?? ''),
                'event_date' => $registrations->first()->event_date !== null ? (string) $registrations->first()->event_date : null,
                'registrations' => $registrations->count(),
                'participants' => $registrations->pluck('people_id')->unique()->count(),
                'people' => $registrations
                    ->map(fn ($row) => [
                        'people_id' => (int) $row->people_id,
                        'name' => (string) ($names[(int) $row->people_id] ?? ''),
                        'registration_type' => $row->registration_type,
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortByDesc(fn (array $version) => $version['event_date'] ?? '')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * One row per live registration of the tenant, with the version's canonical date — the
     * earliest of its `event_version_dates`, matching OpenEventsTrackingRepository.
     */
    protected static function participations(int $appId, int $companyId): Builder
    {
        $versionDates = DB::connection('event')
            ->table('event_version_dates')
            ->where('is_deleted', 0)
            ->selectRaw('event_version_id, MIN(event_date) as event_date')
            ->groupBy('event_version_id');

        return DB::connection('event')
            ->table('event_version_participants as evp')
            ->joinSub($versionDates, 'evd', 'evd.event_version_id', '=', 'evp.event_version_id')
            ->join('participants as p', function ($join) use ($appId, $companyId) {
                $join->on('p.id', '=', 'evp.participant_id')
                    ->where('p.is_deleted', 0)
                    ->where('p.apps_id', $appId)
                    ->where('p.companies_id', $companyId);
            })
            ->join('event_versions as ev', function ($join) {
                $join->on('ev.id', '=', 'evp.event_version_id')
                    ->where('ev.is_deleted', 0);
            })
            ->leftJoin('events as e', 'e.id', '=', 'ev.event_id')
            ->leftJoin('participant_types as pt', 'pt.id', '=', 'evp.participant_type_id')
            ->where('evp.is_deleted', 0)
            ->whereNotNull('p.people_id');
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
     * @param  array<int, array{count_in_window:int,first_in_window:?string,last_in_window:?string,first_ever:?string,last_ever:?string,count_last_year:int,years:array<int,int>}>  $peopleStats
     * @return Collection<int, OrganizationEventActivity>
     */
    protected static function aggregatePerOrganization(
        array $orgs,
        array $peopleStats,
        ?Carbon $fromDate,
        ?Carbon $toDate,
    ): Collection {
        $rows = collect();
        $fromStr = $fromDate?->toDateString();

        foreach ($orgs as $org) {
            $count = 0;
            $uniquePeople = 0;
            $firstEver = null;
            $lastEver = null;
            $hadPrior = false;
            $peopleLastYear = 0;
            $peopleTotal = 0;
            $years = [];

            foreach (array_unique($org['people_ids']) as $peopleId) {
                if (! isset($peopleStats[$peopleId])) {
                    continue;
                }
                $s = $peopleStats[$peopleId];

                $count += $s['count_in_window'];
                if ($s['count_in_window'] > 0) {
                    $uniquePeople++;
                }
                $peopleTotal++;
                if ($s['count_last_year'] > 0) {
                    $peopleLastYear++;
                }
                foreach ($s['years'] as $year => $yearCount) {
                    $years[$year]['count'] = ($years[$year]['count'] ?? 0) + $yearCount;
                    $years[$year]['participants'] = ($years[$year]['participants'] ?? 0) + 1;
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
                participants_last_year: $peopleLastYear,
                participants_total: $peopleTotal,
                by_year: self::yearSeries($years, $fromDate, $toDate),
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

    /**
     * Every year of the window, zeros included, so each company row carries the same columns.
     * Without a from_date the series starts at the company's first active year.
     *
     * @param  array<int, array{count:int, participants:int}>  $years
     * @return list<array{year:int, count:int, participants:int}>
     */
    protected static function yearSeries(array $years, ?Carbon $fromDate, ?Carbon $toDate): array
    {
        $first = $fromDate?->year ?? ($years === [] ? null : min(array_keys($years)));

        if ($first === null) {
            return [];
        }

        $series = [];

        for ($year = $first; $year <= ($toDate ?? Carbon::now())->year; $year++) {
            $series[] = [
                'year' => $year,
                'count' => $years[$year]['count'] ?? 0,
                'participants' => $years[$year]['participants'] ?? 0,
            ];
        }

        return $series;
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
