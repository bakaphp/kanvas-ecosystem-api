<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use RRule\RRule;

class GenerateTimeSlots implements ShouldQueue
{
    public function __construct(
        public int $resourceId,
        public int $ruleId,
        public Carbon $windowFrom,
        public Carbon $windowTo,
    ) {
    }

    public function handle()
    {
        $rule       = ScheduleRules::findOrFail($this->ruleId);
        $resource   = $rule->resource;
        $tz         = $resource->tz ?? $resource->company->timezone  ?? "America/Santo_Domingo";

        // 1) Expand RRULE in venue TZ to get the days
        $rrule = RRule::createFromRfcString($rule->rrule, $rule->start_at->setTimezone($tz));
        if ($rule->end_at) {
            $rrule->getOccurrencesBefore($rule->end_at->setTimezone($tz));
        }
        $occurrences = $rrule->getOccurrencesBetween($this->windowFrom->clone()->tz($tz), $this->windowTo->clone()->tz($tz));

        // 2) For each day occurrence, generate time slots based on day_rrule
        foreach ($occurrences as $dayOccurrence) {
            $dayOccurrence = Carbon::instance($dayOccurrence);

            // If day_rrule exists, use it to generate slots within this day
            if ($rule->day_rrule) {
                $this->generateSlotsForDayWithRRule($rule, $resource, $dayOccurrence, $tz);
            } else {
                // Fallback to old behavior: single slot per occurrence
                $localStart = $dayOccurrence;
                $localEnd = $localStart->copy()->addMinutes($rule->slot_duration_min);

                $this->createTimeSlot($rule, $resource, $localStart, $localEnd, $tz);
            }
        }
    }

    /**
     * Generate time slots for a specific day using day_rrule.
     */
    protected function generateSlotsForDayWithRRule(
        ScheduleRules $rule,
        $resource,
        Carbon $dayOccurrence,
        string $tz
    ): void {
        $base = Carbon::instance($dayOccurrence)->setTimezone($tz);

        // Use the TIME part of DTSTART as the daily open window; a date-only DTSTART would
        // otherwise start at midnight and fill the whole day.
        preg_match('/DTSTART[^:]*:(\d{8})T(\d{2})(\d{2})(\d{2})/', $rule->day_rrule, $dsm);
        $dayStart = $dsm
            ? $base->copy()->setTime((int) $dsm[2], (int) $dsm[3], (int) $dsm[4])
            : $base->copy()->startOfDay();

        // Use the TIME part of UNTIL as the daily close; without this the window runs
        // to end-of-day and BYHOUR (if any) bounds it instead.
        preg_match('/UNTIL=(\d{8})T(\d{2})(\d{2})(\d{2})Z?/', $rule->day_rrule, $um);
        $dayEnd = $um
            ? $base->copy()->setTime((int) $um[2], (int) $um[3], (int) $um[4])
            : $base->copy()->endOfDay();

        // Slot cadence comes from INTERVAL, falling back to the slot length.
        preg_match('/INTERVAL=(\d+)/', $rule->day_rrule, $im);
        $stepMinutes = isset($im[1]) ? max(1, (int) $im[1]) : max(1, $rule->slot_duration_min);

        // Optional hour allow-list used to carve gaps (e.g. lunch) out of a day. We step the
        // window directly instead of expanding the RRULE: php-rrule's BYHOUR handling truncates
        // any sub-daily series mid-day in this version, silently dropping all afternoon slots.
        $allowedHours = null;
        if (preg_match('/BYHOUR=([\d,]+)/', $rule->day_rrule, $hm)) {
            $allowedHours = array_map('intval', explode(',', $hm[1]));
        }

        for ($cursor = $dayStart->copy(); $cursor->lt($dayEnd); $cursor->addMinutes($stepMinutes)) {
            if ($allowedHours !== null && ! in_array((int) $cursor->format('G'), $allowedHours, true)) {
                continue;
            }

            $localStart = $cursor->copy();
            $localEnd = $localStart->copy()->addMinutes($rule->slot_duration_min);

            $this->createTimeSlot($rule, $resource, $localStart, $localEnd, $tz);
        }
    }

    /**
     * Create a single time slot.
     */
    protected function createTimeSlot(
        ScheduleRules $rule,
        $resource,
        Carbon $localStart,
        Carbon $localEnd,
        string $tz
    ): void {
        // Skip if blacked out
        if ($this->isBlackedOut($resource->id, $localStart, $localEnd, $tz)) {
            return;
        }

        // Compute capacity & price
        $capacity = $rule->capacity_override ?? $resource->default_capacity;
        $price = $resource?->getPriceInfoFromDefaultChannel()?->price;

        TimeSlots::upsert([[
          'resources_id'        => $resource->id,
          'resources_type'      => $resource->getMorphClass(),
          'schedule_rules_id'   => $rule->id,
          'start_at'            => $localStart->clone()->setTimezone('UTC'),
          'apps_id'             => $rule->apps_id,
          'companies_id'        => $rule->companies_id,
          'end_at'              => $localEnd->clone()->setTimezone('UTC'),
          'capacity'            => $capacity,
          'initial_capacity'    => $capacity,
          'price_snapshot'      => $price,
          'currency'            => 'USD',
          'updated_at'          => now(),
          'created_at'          => now(),
        ]], uniqueBy: ['resources_id', 'resources_type', 'start_at'], update: [
          'schedule_rules_id','end_at','initial_capacity','price_snapshot','currency','updated_at'
        ]);
    }

    protected function isBlackedOut(int $resourceId, Carbon $start, Carbon $end, string $tz): bool
    {
        $startUtc = $start->clone()->tz('UTC');
        $endUtc   = $end->clone()->tz('UTC');
        return ScheduleException::where('resources_id', $resourceId)
          ->where('kind', 'blackout')
          ->where('window_start', '<', $endUtc)
          ->where('window_end', '>', $startUtc)
          ->exists();
    }
}
