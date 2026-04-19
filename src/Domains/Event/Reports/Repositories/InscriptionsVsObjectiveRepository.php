<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\DataTransferObject\InscriptionsReport;
use Kanvas\Event\Reports\DataTransferObject\InscriptionWeekData;
use Kanvas\Event\Reports\Services\GoalTrackingService;
use Spatie\LaravelData\DataCollection;

class InscriptionsVsObjectiveRepository
{
    /**
     * Week buckets measured in days-before-event.
     */
    public const array WEEK_BUCKETS = [
        5 => ['min_days' => 29, 'max_days' => null],
        4 => ['min_days' => 22, 'max_days' => 28],
        3 => ['min_days' => 15, 'max_days' => 21],
        2 => ['min_days' => 8, 'max_days' => 14],
        1 => ['min_days' => 1, 'max_days' => 7],
    ];

    /**
     * Inscriptions vs goal curve, bucketed by weeks before the event.
     */
    public static function forEventVersion(
        EventVersion $eventVersion,
        bool $cumulative = false,
        ?GoalTrackingService $goalService = null,
    ): InscriptionsReport {
        $goalService ??= new GoalTrackingService();
        $eventStart = self::getEventStart($eventVersion);
        $goal = self::getGoal($eventVersion);

        $registrations = self::loadRegistrationsWithDaysBefore($eventVersion, $eventStart);

        $weeks = [];
        foreach (self::WEEK_BUCKETS as $week => $bounds) {
            $weeks[] = self::buildWeekData($week, $bounds, $registrations, $goal, $cumulative, $goalService);
        }

        return new InscriptionsReport(
            event_version_id: $eventVersion->getId(),
            event_name: (string) $eventVersion->name,
            goal: $goal,
            weeks: new DataCollection(InscriptionWeekData::class, $weeks),
        );
    }

    protected static function getEventStart(EventVersion $eventVersion): ?Carbon
    {
        if ($eventVersion->start_at !== null) {
            return Carbon::parse((string) $eventVersion->start_at);
        }

        $firstDate = DB::connection('event')
            ->table('event_version_dates')
            ->where('event_version_id', $eventVersion->getId())
            ->where('is_deleted', 0)
            ->min('event_date');

        return $firstDate !== null ? Carbon::parse((string) $firstDate) : null;
    }

    protected static function getGoal(EventVersion $eventVersion): int
    {
        $metadata = $eventVersion->metadata;

        if (is_array($metadata)) {
            return (int) ($metadata['max_capacity'] ?? 0);
        }

        return 0;
    }

    /**
     * @return array<int, array{days_before: int, type_name: string, cnt: int}>
     */
    protected static function loadRegistrationsWithDaysBefore(EventVersion $eventVersion, ?Carbon $eventStart): array
    {
        if ($eventStart === null) {
            return [];
        }

        $rows = DB::connection('event')
            ->table('event_version_participants')
            ->leftJoin('participant_types', 'event_version_participants.participant_type_id', '=', 'participant_types.id')
            ->where('event_version_participants.event_version_id', $eventVersion->getId())
            ->where('event_version_participants.is_deleted', 0)
            ->selectRaw(
                'DATEDIFF(?, event_version_participants.created_at) as days_before, COALESCE(participant_types.name, ?) as type_name, COUNT(*) as cnt',
                [$eventStart->toDateString(), 'Unassigned']
            )
            ->groupBy('days_before', 'type_name')
            ->get();

        return $rows->map(fn ($row) => [
            'days_before' => (int) $row->days_before,
            'type_name' => (string) $row->type_name,
            'cnt' => (int) $row->cnt,
        ])->all();
    }

    /**
     * @param  array{min_days: int, max_days: int|null}  $bounds
     * @param  array<int, array{days_before: int, type_name: string, cnt: int}>  $registrations
     */
    protected static function buildWeekData(
        int $week,
        array $bounds,
        array $registrations,
        int $goal,
        bool $cumulative,
        GoalTrackingService $goalService,
    ): InscriptionWeekData {
        $counts = [];
        $total = 0;

        foreach ($registrations as $reg) {
            if (! self::matchesBucket($reg['days_before'], $bounds, $cumulative)) {
                continue;
            }

            $slug = Str::slug($reg['type_name']);
            $counts[$slug] = ($counts[$slug] ?? 0) + $reg['cnt'];
            $total += $reg['cnt'];
        }

        $objective = $goalService->getExpectedEnrollment($goal, $week);

        return new InscriptionWeekData(
            week: $week,
            counts: $counts,
            total: $total,
            objective: $objective,
        );
    }

    /**
     * @param  array{min_days: int, max_days: int|null}  $bounds
     */
    protected static function matchesBucket(int $daysBefore, array $bounds, bool $cumulative): bool
    {
        if ($daysBefore < 1) {
            return false;
        }

        if ($cumulative) {
            return $daysBefore >= $bounds['min_days'];
        }

        if ($daysBefore < $bounds['min_days']) {
            return false;
        }

        return $bounds['max_days'] === null || $daysBefore <= $bounds['max_days'];
    }
}
