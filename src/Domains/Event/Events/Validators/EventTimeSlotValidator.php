<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Validators;

use Illuminate\Support\Facades\DB;

class EventTimeSlotValidator
{
    /**
     * Validates that a time slot is available for a resource
     */
    public static function validateAvailability(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeEventId = null
    ): void {
        if (!$resourcesId || !$resourcesType) {
            return; // No resource to validate against
        }

        // Query to check for overlapping events for the same resource
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
                // Check for time overlap
                $query->where(function ($q) use ($startTime, $endTime) {
                    // Case 1: New event starts before existing ends and ends after existing starts
                    $q->where('evd.start_time', '<', $endTime)
                      ->where('evd.end_time', '>', $startTime);
                });
            })
            ->whereNull('e.deleted_at')
            ->whereNull('ev.deleted_at');

        // Exclude current event if updating
        if ($excludeEventId) {
            $query->where('e.id', '!=', $excludeEventId);
        }

        $conflictingEvents = $query
            ->select('e.id', 'e.name', 'evd.event_date', 'evd.start_time', 'evd.end_time')
            ->first();

        if ($conflictingEvents) {
            throw new \Exception(
                "Time slot is not available. Resource is already booked from {$conflictingEvents->start_time} to {$conflictingEvents->end_time} on {$conflictingEvents->event_date} for event: {$conflictingEvents->name}"
            );
        }
    }

    /**
     * Validates time slot for creating a new event
     */
    public static function validateForCreate(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime
    ): void {
        self::validateAvailability(
            $resourcesId,
            $resourcesType,
            $companiesId,
            $appsId,
            $date,
            $startTime,
            $endTime
        );
    }

    /**
     * Validates time slot for updating an existing event
     */
    public static function validateForUpdate(
        int|string $resourcesId,
        string $resourcesType,
        int $companiesId,
        int $appsId,
        string $date,
        string $startTime,
        string $endTime,
        int $excludeEventId
    ): void {
        self::validateAvailability(
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
}