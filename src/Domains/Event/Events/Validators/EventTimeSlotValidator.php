<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Validators;

use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Exceptions\ValidationException;

class EventTimeSlotValidator
{
    /**
     * Three scenarios:
     * 1. No resource: Skip validation
     * 2. Event has time_slot_id: Validate against the specific time slot
     * 3. Event has no time_slot_id: Check for overlapping events
     */
    public static function validateAvailability(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeEventId = null,
        ?int $timeSlotId = null
    ): void {
        if (! $resourcesId || ! $resourcesType) {
            return;
        }

        if ($timeSlotId) {
            self::validateAgainstTimeSlot($timeSlotId, $companiesId, $appsId, $excludeEventId);
            return;
        }

        self::validateAgainstOverlappingEvents(
            $resourcesId,
            $resourcesType,
            $companiesId,
            $appsId,
            $date,
            $startTime,
            $endTime,
            $excludeEventId
        );
    }

    private static function validateAgainstTimeSlot(
        int $timeSlotId,
        int $companiesId,
        int $appsId,
        ?int $excludeEventId = null
    ): void {
        $timeSlot = TimeSlots::fromApp($appsId)
            ->where('id', $timeSlotId)
            ->first();

        if (! $timeSlot) {
            throw new ValidationException('Time slot not found.');
        }

        // For updates, we need to check if we're excluding the current event from capacity calculation
        // This ensures we don't count the event being updated against available capacity
        if ($excludeEventId && $timeSlot->isFullyBooked()) {
            // If excluding an event, check if slot would be fully booked without counting that event
            $bookingsCount = DB::connection('event')
                ->table('event_versions')
                ->where('time_slot_id', $timeSlotId)
                ->where('event_id', '!=', $excludeEventId)
                ->where('is_deleted', 0)
                ->count();

            if ($bookingsCount >= $timeSlot->initial_capacity) {
                throw new ValidationException(
                    "Time slot is fully booked. No capacity remaining."
                );
            }
        } elseif ($timeSlot->isFullyBooked()) {
            throw new ValidationException(
                "Time slot is fully booked. No capacity remaining."
            );
        }
    }

    private static function validateAgainstOverlappingEvents(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeEventId = null
    ): void {
        $query = DB::connection('event')
            ->table('events as e')
            ->join('event_versions as ev', 'e.id', '=', 'ev.event_id')
            ->join('event_version_dates as evd', 'ev.id', '=', 'evd.event_version_id')
            ->where('e.resources_id', $resourcesId)
            ->where('e.resources_type', $resourcesType)
            ->where('e.companies_id', $companiesId)
            ->where('e.apps_id', $appsId)
            ->whereDate('evd.event_date', $date)
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where('evd.start_time', '<', $endTime)
                      ->where('evd.end_time', '>', $startTime);
                });
            })
            ->whereNull('e.deleted_at')
            ->whereNull('ev.deleted_at');

        if ($excludeEventId) {
            $query->where('e.id', '!=', $excludeEventId);
        }

        $conflictingEvents = $query
            ->select('e.id', 'e.name', 'evd.event_date', 'evd.start_time', 'evd.end_time')
            ->first();

        if ($conflictingEvents) {
            throw new ValidationException(
                "Time slot is not available. Resource is already booked from {$conflictingEvents->start_time} to {$conflictingEvents->end_time} on {$conflictingEvents->event_date} for event: {$conflictingEvents->name}"
            );
        }

        $startDateTime = $date . ' ' . $startTime;
        $endDateTime = $date . ' ' . $endTime;

        $conflictingHold = DB::connection('event')
            ->table('event_holds')
            ->where('resources_id', $resourcesId)
            ->where('resources_type', $resourcesType)
            ->where('companies_id', $companiesId)
            ->where('apps_id', $appsId)
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->where('start_at', '<', $endDateTime)
                      ->where('end_at', '>', $startDateTime);
            })
            ->first();

        if ($conflictingHold) {
            throw new ValidationException(
                "Time slot is currently held by another user and will be available after the hold expires."
            );
        }
    }

    public static function validateForCreate(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $timeSlotId = null
    ): void {
        self::validateAvailability(
            $resourcesId,
            $resourcesType,
            $companiesId,
            $appsId,
            $date,
            $startTime,
            $endTime,
            null,
            $timeSlotId
        );
    }

    public static function validateForUpdate(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        int $excludeEventId,
        ?int $timeSlotId = null
    ): void {
        self::validateAvailability(
            $resourcesId,
            $resourcesType,
            $companiesId,
            $appsId,
            $date,
            $startTime,
            $endTime,
            $excludeEventId,
            $timeSlotId
        );
    }
}
