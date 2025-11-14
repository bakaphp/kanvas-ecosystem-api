<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
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
        // Check if a schedule rule already exists for this day
        $existingRule = ScheduleRules::where('resources_id', $this->resource->getId())
            ->where('resources_type', $this->resource->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereJsonContains('metadata->operation_day', $dayName)
            ->first();

        // Create start_at and end_at datetimes
        // Use today's date with the open/close times as a template
        $startAt = Carbon::parse('today ' . $openTime);

        // Build RRULE for weekly recurrence on this specific day
        // FREQ=WEEKLY;BYDAY=MO (for Monday, TU for Tuesday, etc.)
        $rrule = "FREQ=WEEKLY;BYDAY={$dayCode}";

        // Build day_rrule with the operating hours
        // This defines the time slots within each day
        $dayRrule = "DTSTART:{$openTime}\nDTEND:{$closeTime}";

        if ($existingRule) {
            // Update existing rule
            $existingRule->update([
                'start_at' => $startAt,
                'rrule' => $rrule,
                'day_rrule' => $dayRrule,
                'slot_duration_min' => $this->slotDurationMinutes,
                'lead_time_min' => $this->leadTimeMin,
                'cutoff_time_min' => $this->cutoffTimeMin,
                'capacity_override' => $this->capacityOverride,
                'metadata' => array_merge($existingRule->metadata ?? [], [
                    'operation_day' => $dayName,
                    'created_from' => 'operation_days',
                ]),
            ]);

            return $existingRule;
        }

        // Create new schedule rule
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
