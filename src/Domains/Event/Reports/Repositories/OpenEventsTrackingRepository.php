<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Reports\DataTransferObject\OpenEventTrackingRow;
use Kanvas\Event\Reports\Services\GoalTrackingService;

class OpenEventsTrackingRepository
{
    /**
     * Upcoming events in the next N weeks with enrollment-vs-goal color coding.
     *
     * @param  array<string, mixed>  $filters
     *   Supported keys:
     *     - weeks_ahead: int (default 7)
     *     - event_type_id: int|null
     *     - event_class_id: int|null
     *     - event_category_id: int|null
     *     - search: string|null (event name)
     *     - color: string|null (green|yellow|red — filters results client-side after calc)
     *     - has_goal: bool|null (true = only events with max_capacity > 0)
     *
     * @return Collection<int, OpenEventTrackingRow>
     */
    public static function forCompany(
        AppInterface $app,
        Companies $company,
        array $filters = [],
        ?GoalTrackingService $goalService = null,
    ): Collection {
        $goalService ??= new GoalTrackingService();
        $weeksAhead = (int) ($filters['weeks_ahead'] ?? 7);
        $now = Carbon::now();
        $endDate = $now->copy()->addWeeks($weeksAhead);

        $query = DB::connection('event')
            ->table('event_versions')
            ->leftJoinSub(
                DB::connection('event')
                    ->table('event_version_dates')
                    ->where('is_deleted', 0)
                    ->selectRaw('event_version_id, MIN(event_date) as first_event_date')
                    ->groupBy('event_version_id'),
                'first_date',
                'event_versions.id',
                '=',
                'first_date.event_version_id'
            )
            ->join('events', 'event_versions.event_id', '=', 'events.id')
            ->where('event_versions.apps_id', $app->getId())
            ->where('event_versions.companies_id', $company->getId())
            ->where('event_versions.is_deleted', 0)
            ->where('events.is_deleted', 0)
            ->where(function (Builder $q) use ($now, $endDate) {
                $q->whereBetween('event_versions.start_at', [$now->toDateTimeString(), $endDate->toDateTimeString()])
                    ->orWhereBetween('first_date.first_event_date', [$now->toDateString(), $endDate->toDateString()]);
            });

        self::applyDomainFilters($query, $filters);

        $versions = $query
            ->orderByRaw('COALESCE(event_versions.start_at, first_date.first_event_date) ASC')
            ->get([
                'event_versions.id',
                'event_versions.name',
                'event_versions.start_at',
                'event_versions.metadata',
                'first_date.first_event_date',
            ]);

        if ($versions->isEmpty()) {
            return collect();
        }

        $versionIds = $versions->pluck('id')->all();
        $countsByVersion = self::loadCounts($versionIds);

        $rows = $versions->map(fn ($version) => self::buildRow(
            $version,
            $countsByVersion[(int) $version->id] ?? [],
            $now,
            $goalService,
        ));

        if (! empty($filters['color'])) {
            $rows = $rows->filter(fn (OpenEventTrackingRow $r) => $r->color === $filters['color']);
        }

        if (! empty($filters['has_goal'])) {
            $rows = $rows->filter(fn (OpenEventTrackingRow $r) => $r->goal > 0);
        }

        return $rows->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected static function applyDomainFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['event_type_id'])) {
            $query->where('events.event_type_id', (int) $filters['event_type_id']);
        }

        if (! empty($filters['event_class_id'])) {
            $query->where('events.event_class_id', (int) $filters['event_class_id']);
        }

        if (! empty($filters['event_category_id'])) {
            $query->where('events.event_category_id', (int) $filters['event_category_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('event_versions.name', 'LIKE', $search)
                    ->orWhere('events.name', 'LIKE', $search);
            });
        }
    }

    /**
     * @param  array<int>  $versionIds
     * @return array<int, array<string, int>>
     */
    protected static function loadCounts(array $versionIds): array
    {
        $countRows = DB::connection('event')
            ->table('event_version_participants')
            ->leftJoin('participant_types', 'event_version_participants.participant_type_id', '=', 'participant_types.id')
            ->whereIn('event_version_participants.event_version_id', $versionIds)
            ->where('event_version_participants.is_deleted', 0)
            ->selectRaw('event_version_participants.event_version_id as version_id, COALESCE(participant_types.name, ?) as type_name, COUNT(*) as cnt', ['Unassigned'])
            ->groupBy('version_id', 'type_name')
            ->get();

        $countsByVersion = [];
        foreach ($countRows as $row) {
            $versionId = (int) $row->version_id;
            $slug = Str::slug((string) $row->type_name);
            $countsByVersion[$versionId][$slug] = (int) $row->cnt;
        }

        return $countsByVersion;
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected static function buildRow(
        object $version,
        array $counts,
        Carbon $now,
        GoalTrackingService $goalService,
    ): OpenEventTrackingRow {
        $total = array_sum($counts);

        $metadata = $version->metadata !== null ? json_decode((string) $version->metadata, true) : [];
        $goal = is_array($metadata) ? (int) ($metadata['max_capacity'] ?? 0) : 0;

        $startAt = $version->start_at !== null
            ? Carbon::parse((string) $version->start_at)
            : ($version->first_event_date !== null ? Carbon::parse((string) $version->first_event_date) : null);

        $weeksUntil = $startAt !== null ? $goalService->getWeeksUntil($startAt, $now) : 0;
        $expected = $goalService->getExpectedEnrollment($goal, $weeksUntil);

        return new OpenEventTrackingRow(
            event_version_id: (int) $version->id,
            event_name: (string) $version->name,
            event_date: $startAt?->toDateString(),
            counts: $counts,
            total_inscribed: $total,
            goal: $goal,
            goal_percentage: $goalService->getAchievementPercentage($total, $expected),
            color: $goalService->getColor($total, $expected),
        );
    }
}
