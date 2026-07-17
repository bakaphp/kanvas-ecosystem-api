<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
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
        protected bool $generateSlots = true,
        protected ?Carbon $startAt = null,
        protected ?Carbon $endAt = null,
        protected ?int $horizonDays = null,
    ) {
    }

    public function execute(): array
    {
        $upsertedRules = [];

        foreach (self::DAY_MAP as $dayName => $dayCode) {
            $dayConfig = $this->operationDays[$dayName] ?? null;
            $active = (bool) ($dayConfig['active'] ?? false);

            if (! $active) {
                $this->deleteRuleForDay($dayName);
                continue;
            }

            $periods = $this->resolvePeriods($dayConfig);

            if (empty($periods)) {
                $this->deleteRuleForDay($dayName);
                continue;
            }

            $rule = $this->upsertRuleForDay($dayName, $dayCode, $periods);

            if ($rule) {
                $upsertedRules[] = $rule;

                if ($this->generateSlots) {
                    $this->dispatchTimeSlotGeneration($rule);
                }
            }
        }

        return $upsertedRules;
    }

    protected function resolvePeriods(array $dayConfig): array
    {
        if (isset($dayConfig['periods']) && is_array($dayConfig['periods'])) {
            return $this->parsePeriods($dayConfig['periods']);
        }

        $open = $this->parseTime($dayConfig['open'] ?? null);
        $close = $this->parseTime($dayConfig['close'] ?? null);

        if (! $open || ! $close) {
            return [];
        }

        return [['open' => $open, 'close' => $close]];
    }

    protected function upsertRuleForDay(string $dayName, string $dayCode, array $periods): ?ScheduleRules
    {
        $firstPeriod = $periods[0];
        $baseDate = $this->startAt?->toDateString() ?? 'today';
        $startAt = Carbon::parse($baseDate . ' ' . $firstPeriod['open']);
        $dtstart = $startAt->format('Ymd\THis');
        $rrule = "DTSTART:{$dtstart}\nRRULE:FREQ={$this->frequency};BYDAY={$dayCode}";

        $allHours = [];
        foreach ($periods as $period) {
            $openCarbon = Carbon::parse('today ' . $period['open']);
            $closeCarbon = Carbon::parse('today ' . $period['close']);

            $startHour = (int) $openCarbon->format('H');
            $endHour = (int) $closeCarbon->format('H');
            $closeMinute = (int) $closeCarbon->format('i');
            $lastHour = $closeMinute > 0 ? $endHour : $endHour - 1;
            $allHours = array_merge($allHours, range($startHour, $lastHour));
        }
        $allHours = array_unique($allHours);
        sort($allHours);
        $hoursString = implode(',', $allHours);

        $startMinute = (int) $startAt->format('i');
        $minuteOffset = $startMinute % $this->slotDurationMinutes;
        $minutes = [];
        for ($m = $minuteOffset; $m < 60; $m += $this->slotDurationMinutes) {
            $minutes[] = $m;
        }
        $minutesString = implode(',', $minutes);

        $dayDtstart = $startAt->format('Ymd\THis');
        $dayRrule = "DTSTART:{$dayDtstart}\nRRULE:FREQ=MINUTELY;INTERVAL={$this->slotDurationMinutes};BYHOUR={$hoursString};BYMINUTE={$minutesString}";

        $metadata = [
            'operation_day' => $dayName,
            'created_from' => 'operation_days',
            'periods' => $periods,
        ];

        if ($this->scheduleType) {
            $metadata['schedule_type'] = $this->scheduleType;
        }

        $rule = ScheduleRules::withoutGlobalScopes()->updateOrCreate(
            $this->naturalKey($dayName),
            [
                'start_at' => $startAt,
                'end_at' => $this->endAt?->clone(),
                'rrule' => $rrule,
                'day_rrule' => $dayRrule,
                'slot_duration_min' => $this->slotDurationMinutes,
                'lead_time_min' => $this->leadTimeMin,
                'cutoff_time_min' => $this->cutoffTimeMin,
                'capacity_override' => $this->capacityOverride,
                'metadata' => $metadata,
                'is_deleted' => false,
            ]
        );

        if ($rule->wasChanged(['rrule', 'day_rrule', 'slot_duration_min', 'capacity_override'])) {
            $rule->deleteUpcomingTimeSlots();
        }

        return $rule;
    }

    protected function deleteRuleForDay(string $dayName): void
    {
        $rule = ScheduleRules::withoutGlobalScopes()->where($this->naturalKey($dayName))->first();

        if (! $rule) {
            return;
        }

        $rule->deleteUpcomingTimeSlots();
        $rule->forceDelete();
    }

    protected function naturalKey(string $dayName): array
    {
        return [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'resources_type' => $this->resource->getMorphClass(),
            'resources_id' => $this->resource->getId(),
            'operation_day' => $dayName,
        ];
    }

    protected function dispatchTimeSlotGeneration(ScheduleRules $scheduleRule): void
    {
        [$windowFrom, $windowTo] = GenerateTimeSlots::resolveWindow(
            $scheduleRule->start_at,
            $scheduleRule->end_at,
            $this->horizonDays ?? GenerateTimeSlots::horizonDaysForApp($this->app)
        );

        dispatch(new GenerateTimeSlots(
            $this->resource->getId(),
            $scheduleRule->id,
            $windowFrom,
            $windowTo
        ));
    }

    protected function parseTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (Exception) {
            return null;
        }
    }

    protected function parsePeriods(array $periodsConfig): array
    {
        $periods = [];

        foreach ($periodsConfig as $period) {
            $open = $this->parseTime($period['open'] ?? null);
            $close = $this->parseTime($period['close'] ?? null);

            if ($open && $close) {
                $periods[] = ['open' => $open, 'close' => $close];
            }
        }

        return $periods;
    }
}
