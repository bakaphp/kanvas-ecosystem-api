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
        protected int $cutoffTimeMin = 0,
        protected string $frequency = 'WEEKLY',
        protected ?string $scheduleType = null,
    ) {
    }

    public function execute(): array
    {
        $this->clearExistingRules();

        $createdRules = [];

        foreach ($this->operationDays as $dayName => $dayConfig) {
            if (! ($dayConfig['active'] ?? false)) {
                continue;
            }

            $openTime = $this->parseTime($dayConfig['open'] ?? null);
            $closeTime = $this->parseTime($dayConfig['close'] ?? null);

            if (! $openTime || ! $closeTime) {
                continue;
            }

            $dayCode = self::DAY_MAP[strtolower($dayName)] ?? null;
            if (! $dayCode) {
                continue;
            }

            $scheduleRule = $this->createScheduleRuleForDay($dayName, $dayCode, $openTime, $closeTime);

            if ($scheduleRule) {
                $createdRules[] = $scheduleRule;
                $this->dispatchTimeSlotGeneration($scheduleRule);
            }
        }

        return $createdRules;
    }

    protected function createScheduleRuleForDay(
        string $dayName,
        string $dayCode,
        string $openTime,
        string $closeTime
    ): ?ScheduleRules {
        $startAt = Carbon::parse('today ' . $openTime);
        $dtstart = $startAt->format('Ymd\THis');
        $rrule = "DTSTART:{$dtstart}\nRRULE:FREQ={$this->frequency};BYDAY={$dayCode}";

        $openCarbon = Carbon::parse('today ' . $openTime);
        $closeCarbon = Carbon::parse('today ' . $closeTime);

        $startHour = (int) $openCarbon->format('H');
        $endHour = (int) $closeCarbon->format('H');
        $hours = range($startHour, $endHour - 1);
        $hoursString = implode(',', $hours);

        $minutes = [];
        for ($m = 0; $m < 60; $m += $this->slotDurationMinutes) {
            $minutes[] = $m;
        }
        $minutesString = implode(',', $minutes);

        $dayDtstart = $openCarbon->format('Ymd\THis');
        $dayRrule = "DTSTART:{$dayDtstart}\nRRULE:FREQ=MINUTELY;INTERVAL={$this->slotDurationMinutes};BYHOUR={$hoursString};BYMINUTE={$minutesString}";

        $metadata = [
            'operation_day' => $dayName,
            'created_from' => 'operation_days',
        ];

        if ($this->scheduleType) {
            $metadata['schedule_type'] = $this->scheduleType;
        }

        return ScheduleRules::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->resource->getId(),
            'resources_type' => $this->resource->getMorphClass(),
            'start_at' => $startAt,
            'end_at' => null,
            'rrule' => $rrule,
            'day_rrule' => $dayRrule,
            'slot_duration_min' => $this->slotDurationMinutes,
            'lead_time_min' => $this->leadTimeMin,
            'cutoff_time_min' => $this->cutoffTimeMin,
            'capacity_override' => $this->capacityOverride,
            'metadata' => $metadata,
        ]);
    }

    protected function dispatchTimeSlotGeneration(ScheduleRules $scheduleRule): void
    {
        dispatch(new GenerateTimeSlots(
            $this->resource->getId(),
            $scheduleRule->id,
            Carbon::now(),
            Carbon::now()->addYear()
        ));
    }

    protected function parseTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function clearExistingRules(): void
    {
        $existingRules = ScheduleRules::where('resources_id', $this->resource->getId())
            ->where('resources_type', $this->resource->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->get();

        foreach ($existingRules as $rule) {
            $rule->deleteUpcomingTimeSlots();
            $rule->delete();
        }
    }
}
