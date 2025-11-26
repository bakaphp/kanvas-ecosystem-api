<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;

class CreateScheduleRulesFromOperationDaysAction
{
    /**
     * Map of day names to RRULE day codes.
     */
    private const DAY_MAP = [
        'monday' => 'MO',
        'tuesday' => 'TU',
        'wednesday' => 'WE',
        'thursday' => 'TH',
        'friday' => 'FR',
        'saturday' => 'SA',
        'sunday' => 'SU',
    ];

    public function __construct(
        protected Model $resource,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $operationDays,
        protected int $slotDurationMinutes = 15,
        protected ?int $capacityOverride = null,
        protected int $leadTimeMin = 0,
        protected int $cutoffTimeMin = 0
    ) {
    }

    /**
     * Execute the action to create schedule rules.
     */
    public function execute(): array
    {
        // Delete all existing schedule rules for this resource first
        $this->clearExistingRules();

        $createdRules = [];

        foreach ($this->operationDays as $dayName => $dayConfig) {
            // Skip if the day is not active
            if (! ($dayConfig['active'] ?? false)) {
                continue;
            }

            // Parse open and close times
            $openTime = $this->parseTime($dayConfig['open'] ?? null);
            $closeTime = $this->parseTime($dayConfig['close'] ?? null);

            if (! $openTime || ! $closeTime) {
                continue;
            }

            // Get the RRULE day code
            $dayCode = self::DAY_MAP[strtolower($dayName)] ?? null;
            if (! $dayCode) {
                continue;
            }

            // Create the schedule rule for this day
            $scheduleRule = $this->createScheduleRuleForDay($dayName, $dayCode, $openTime, $closeTime);

            if ($scheduleRule) {
                $createdRules[] = $scheduleRule;

                // Dispatch job to generate time slots
                $this->dispatchTimeSlotGeneration($scheduleRule);
            }
        }

        return $createdRules;
    }

    /**
     * Create a schedule rule for a specific day.
     */
    protected function createScheduleRuleForDay(
        string $dayName,
        string $dayCode,
        string $openTime,
        string $closeTime
    ): ?ScheduleRules {
        // Create start_at - use the opening time on the earliest occurrence of this day
        $startAt = Carbon::parse('today ' . $openTime);

        // Build RRULE for weekly recurrence on this specific day
        // This generates which days to create slots for
        // Format: DTSTART:20240101T070000\nRRULE:FREQ=WEEKLY;BYDAY=MO
        $dtstart = $startAt->format('Ymd\THis');
        $rrule = "DTSTART:{$dtstart}\nRRULE:FREQ=WEEKLY;BYDAY={$dayCode}";

        // Build day_rrule for time slots within each day
        // This generates slots every X minutes between open and close times
        // Format: DTSTART:20240101T070000\nRRULE:FREQ=MINUTELY;INTERVAL=15;...
        $openCarbon = Carbon::parse('today ' . $openTime);
        $closeCarbon = Carbon::parse('today ' . $closeTime);

        // Generate hour range
        $startHour = (int)$openCarbon->format('H');
        $endHour = (int)$closeCarbon->format('H');
        $hours = range($startHour, $endHour);
        $hoursString = implode(',', $hours);

        // Generate minute intervals based on slot duration
        $minutes = [];
        for ($m = 0; $m < 60; $m += $this->slotDurationMinutes) {
            $minutes[] = $m;
        }
        $minutesString = implode(',', $minutes);

        // Build the day RRULE with DTSTART
        $dayDtstart = $openCarbon->format('Ymd\THis');
        $dayRrule = "DTSTART:{$dayDtstart}\nRRULE:FREQ=MINUTELY;INTERVAL={$this->slotDurationMinutes};BYHOUR={$hoursString};BYMINUTE={$minutesString}";

        // Create new schedule rule (existing ones were already deleted)
        return ScheduleRules::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->resource->getId(),
            'resources_type' => $this->resource->getMorphClass(),
            'start_at' => $startAt,
            'end_at' => null, // No end date - recurring indefinitely
            'rrule' => $rrule,
            'day_rrule' => $dayRrule,
            'slot_duration_min' => $this->slotDurationMinutes,
            'lead_time_min' => $this->leadTimeMin,
            'cutoff_time_min' => $this->cutoffTimeMin,
            'capacity_override' => $this->capacityOverride,
            'metadata' => [
                'operation_day' => $dayName,
                'created_from' => 'operation_days',
            ],
        ]);
    }

    /**
     * Dispatch the time slot generation job.
     */
    protected function dispatchTimeSlotGeneration(ScheduleRules $scheduleRule): void
    {
        $windowFrom = Carbon::now();
        $windowTo = Carbon::now()->addYear();

        dispatch(new GenerateTimeSlots(
            $this->resource->getId(),
            $scheduleRule->id,
            $windowFrom,
            $windowTo
        ));
    }

    /**
     * Parse time string to 24-hour format (HH:MM).
     */
    protected function parseTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        try {
            // Parse times like "07:00 AM", "06:00 PM", or "14:00"
            return Carbon::parse($time)->format('H:i');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Delete existing schedule rules for this resource that were created from operation days.
     */
    public function clearExistingRules(): void
    {
        $existingRules = ScheduleRules::where('resources_id', $this->resource->getId())
            ->where('resources_type', $this->resource->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->get();

        foreach ($existingRules as $rule) {
            // Delete upcoming time slots for this rule
            $rule->deleteUpcomingTimeSlots();
            // Delete the rule itself
            $rule->delete();
        }
    }
}
