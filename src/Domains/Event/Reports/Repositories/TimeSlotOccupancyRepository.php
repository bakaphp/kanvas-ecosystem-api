<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Reports\DataTransferObject\TimeSlotOccupancyBucket;

class TimeSlotOccupancyRepository
{
    /**
     * Occupancy over a window, starting from `time_slots` so the empty hours are visible.
     *
     * Aggregating from `event_versions` instead would give the booked players but neither the
     * capacity denominator (it lives on `time_slots.initial_capacity`) nor the slots nobody
     * booked — which are exactly the valley hours the dashboard wants to surface.
     *
     * One query for the whole window: reading `booked_slots` off each TimeSlots model runs a
     * SUM per row, which is fine for a day and falls over on a month.
     *
     * @return array{
     *     capacity:int, booked:int, occupancy_percentage:float, slots_count:int,
     *     byHour:array<int, TimeSlotOccupancyBucket>, byDay:array<int, TimeSlotOccupancyBucket>,
     *     peak:?TimeSlotOccupancyBucket, lowest:?TimeSlotOccupancyBucket
     * }
     */
    public static function occupancy(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
        string $timezone = 'UTC',
        ?int $resourcesId = null,
        ?string $resourcesType = null,
    ): array {
        $slots = DB::connection('event')
            ->table('time_slots as ts')
            ->leftJoin('event_versions as ev', function ($join) {
                $join->on('ev.time_slot_id', '=', 'ts.id')
                    ->where('ev.is_deleted', 0);
            })
            ->where('ts.apps_id', $app->getId())
            ->where('ts.companies_id', $company->getId())
            ->where('ts.is_deleted', 0)
            ->whereBetween('ts.start_at', [$start, $end])
            ->when($resourcesId !== null, fn ($q) => $q->where('ts.resources_id', $resourcesId))
            ->when($resourcesType !== null, fn ($q) => $q->where('ts.resources_type', $resourcesType))
            ->groupBy('ts.id', 'ts.start_at', 'ts.initial_capacity')
            ->select([
                'ts.id',
                'ts.start_at',
                'ts.initial_capacity',
                DB::raw('COALESCE(SUM(ev.total_attendees), 0) as booked'),
            ])
            ->get();

        $totalCapacity = 0;
        $totalBooked = 0;
        $byHour = [];
        $byDay = [];

        foreach ($slots as $slot) {
            $capacity = (int) $slot->initial_capacity;
            $booked = (int) $slot->booked;

            $totalCapacity += $capacity;
            $totalBooked += $booked;

            // start_at is stored in UTC; the buckets have to read as venue wall clock or the
            // "peak hour" lands hours away from when players actually tee off.
            $localStart = Carbon::parse((string) $slot->start_at, 'UTC')->setTimezone($timezone);

            self::addTo($byHour, $localStart->format('H:i'), $capacity, $booked);
            self::addTo($byDay, $localStart->format('Y-m-d'), $capacity, $booked);
        }

        ksort($byHour);
        ksort($byDay);

        $hourBuckets = self::toBuckets($byHour);
        $dayBuckets = self::toBuckets($byDay);

        return [
            'capacity' => $totalCapacity,
            'booked' => $totalBooked,
            'occupancy_percentage' => $totalCapacity > 0 ? round($totalBooked / $totalCapacity * 100, 2) : 0.0,
            'slots_count' => $slots->count(),
            'byHour' => $hourBuckets,
            'byDay' => $dayBuckets,
            'peak' => self::pick($hourBuckets, highest: true),
            'lowest' => self::pick($hourBuckets, highest: false),
        ];
    }

    /**
     * @param  array<string, array{capacity:int, booked:int, slots:int}>  $bucket
     */
    private static function addTo(array &$bucket, string $key, int $capacity, int $booked): void
    {
        if (! isset($bucket[$key])) {
            $bucket[$key] = ['capacity' => 0, 'booked' => 0, 'slots' => 0];
        }

        $bucket[$key]['capacity'] += $capacity;
        $bucket[$key]['booked'] += $booked;
        $bucket[$key]['slots']++;
    }

    /**
     * @param  array<string, array{capacity:int, booked:int, slots:int}>  $bucket
     * @return array<int, TimeSlotOccupancyBucket>
     */
    private static function toBuckets(array $bucket): array
    {
        $rows = [];

        foreach ($bucket as $label => $totals) {
            $rows[] = TimeSlotOccupancyBucket::fromTotals(
                (string) $label,
                $totals['capacity'],
                $totals['booked'],
                $totals['slots'],
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, TimeSlotOccupancyBucket>  $buckets
     */
    private static function pick(array $buckets, bool $highest): ?TimeSlotOccupancyBucket
    {
        $withCapacity = array_values(array_filter($buckets, fn (TimeSlotOccupancyBucket $b) => $b->capacity > 0));

        if ($withCapacity === []) {
            return null;
        }

        usort(
            $withCapacity,
            fn (TimeSlotOccupancyBucket $a, TimeSlotOccupancyBucket $b) => $a->occupancy_percentage <=> $b->occupancy_percentage
        );

        return $highest ? end($withCapacity) : $withCapacity[0];
    }
}
