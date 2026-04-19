<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\DataTransferObject\InscriptionsReport;
use Kanvas\Event\Reports\DataTransferObject\InscriptionWeekData;
use Kanvas\Event\Reports\Services\GoalTrackingService;
use Spatie\LaravelData\DataCollection;

class InscriptionsVsHistoricalRepository
{
    public const array WEEK_BUCKETS = InscriptionsVsObjectiveRepository::WEEK_BUCKETS;

    /**
     * @param  list<string>|null  $includeTypes
     * @param  list<string>  $excludeTypes
     */
    public static function forEventVersion(
        EventVersion $eventVersion,
        bool $cumulative = false,
        ?array $includeTypes = null,
        array $excludeTypes = [],
        ?GoalTrackingService $goalService = null,
    ): InscriptionsReport {
        $goalService ??= new GoalTrackingService();

        $currentReport = InscriptionsVsObjectiveRepository::forEventVersion(
            $eventVersion,
            $cumulative,
            $includeTypes,
            $excludeTypes,
            $goalService,
        );

        $historicalAverages = self::computeHistoricalAverages($eventVersion, $cumulative, $includeTypes, $excludeTypes);

        $mergedWeeks = [];
        foreach ($currentReport->weeks as $weekData) {
            $mergedWeeks[] = new InscriptionWeekData(
                week: $weekData->week,
                counts: $weekData->counts,
                total: $weekData->total,
                objective: $historicalAverages[$weekData->week] ?? 0,
            );
        }

        return new InscriptionsReport(
            event_version_id: $currentReport->event_version_id,
            event_name: $currentReport->event_name,
            goal: $currentReport->goal,
            weeks: new DataCollection(InscriptionWeekData::class, $mergedWeeks),
        );
    }

    /**
     * @param  list<string>|null  $includeTypes
     * @param  list<string>  $excludeTypes
     * @return array<int, int>
     */
    protected static function computeHistoricalAverages(
        EventVersion $eventVersion,
        bool $cumulative,
        ?array $includeTypes,
        array $excludeTypes,
    ): array {
        $pastVersions = self::getPastVersions($eventVersion);

        if ($pastVersions->isEmpty()) {
            return array_fill_keys(array_keys(self::WEEK_BUCKETS), 0);
        }

        $totals = array_fill_keys(array_keys(self::WEEK_BUCKETS), 0);

        foreach ($pastVersions as $pastVersion) {
            $pastReport = InscriptionsVsObjectiveRepository::forEventVersion(
                $pastVersion,
                $cumulative,
                $includeTypes,
                $excludeTypes,
            );
            foreach ($pastReport->weeks as $weekData) {
                $totals[$weekData->week] += $weekData->total;
            }
        }

        $count = $pastVersions->count();

        return array_map(
            fn (int $sum): int => (int) round($sum / $count),
            $totals,
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, EventVersion>
     */
    protected static function getPastVersions(EventVersion $eventVersion)
    {
        $now = Carbon::now();

        $pastVersionIds = DB::connection('event')
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
            ->where('event_versions.event_id', $eventVersion->event_id)
            ->where('event_versions.id', '!=', $eventVersion->getId())
            ->where('event_versions.is_deleted', 0)
            ->where(function (Builder $q) use ($now) {
                $q->where('event_versions.start_at', '<', $now->toDateTimeString())
                    ->orWhere('first_date.first_event_date', '<', $now->toDateString());
            })
            ->pluck('event_versions.id')
            ->all();

        if (empty($pastVersionIds)) {
            return collect();
        }

        return EventVersion::whereIn('id', $pastVersionIds)->get();
    }
}
