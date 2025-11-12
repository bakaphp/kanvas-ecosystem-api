<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class CompanyWorkHoursTool implements ContextToolInterface
{
    protected Carbon $now;
    protected ?array $weeklyHours = null;
    protected ?array $simpleHours = null;
    protected ?array $workingDays = null;

    public function __construct(
        protected Model $entity
    ) {
        $tz = $this->entity->company->get('timezone') ?? 'UTC';
        $this->now = Carbon::now($tz);

        $this->simpleHours = $this->entity->company->get(ConfigurationEnum::WORKING_HOURS->value) ?? null;
        $this->workingDays = $this->normalizeDays(
            $this->entity->company->get(ConfigurationEnum::WORKING_DAYS->value) ?? []
        );

        if ($this->looksLikeWeeklyMap($this->simpleHours ?? [])) {
            $this->weeklyHours = $this->simpleHours;
            $this->simpleHours = null;
        }
    }

    #[Override]
    public function execute(array $params = []): array
    {
        [$opensAt, $closesAt] = $this->getTodayOpenClose($this->now);

        $status = $this->getStatus($this->now, $opensAt, $closesAt);
        $nextOpen = $this->getNextOpenDateTime($this->now, $opensAt, $closesAt);

        return [
            'status' => $status,
            'weekday' => $this->now->dayName,
            'opens_at_local' => $opensAt?->format('H:i') ?? '',
            'closes_at_local' => $closesAt?->format('H:i') ?? '',
            'next_open_iso' => $nextOpen->toIso8601String(),
            'next_open_human' => $nextOpen->format('l jS \\a\\t h:i A'),
            'current_time' => $this->now->format('Y-m-d H:i:s'),
        ];
    }

    protected function getStatus(Carbon $now, ?Carbon $opensAt, ?Carbon $closesAt): string
    {
        if (! $this->isWorkingDay($now)) {
            return 'after_hours';
        }

        if (! $opensAt || ! $closesAt) {
            return 'after_hours';
        }

        return $now->between($opensAt, $closesAt, true) ? 'work_hours' : 'after_hours';
    }

    protected function getNextOpenDateTime(Carbon $now, ?Carbon $todayOpen, ?Carbon $todayClose): Carbon
    {
        if ($this->isWorkingDay($now) && $todayOpen && $todayClose) {
            if ($now->lt($todayOpen)) {
                return $todayOpen->copy();
            }
            if ($now->between($todayOpen, $todayClose, true)) {
                return $this->nextWorkingDayOpen($now->copy()->addDay());
            }

            return $this->nextWorkingDayOpen($now->copy()->addDay());
        }

        return $this->nextWorkingDayOpen($now->copy()->addDay());
    }

    protected function getTodayOpenClose(Carbon $ref): array
    {
        if ($this->simpleHours) {
            $open = $this->makeTime($ref, $this->simpleHours['opens_at_local'] ?? '09:00:00');
            $close = $this->makeTime($ref, $this->simpleHours['closes_at_local'] ?? '21:00:00');

            return [$open, $close];
        }

        if ($this->weeklyHours) {
            return $this->getOpenCloseForDayName($ref->dayName, $ref);
        }

        return [null, null];
    }

    protected function getOpenCloseForDayName(string $dayName, Carbon $ref): array
    {
        $key = $this->normalizeDayName($dayName);
        $hours = $this->weeklyHours[$key] ?? null;

        if (! $hours || trim($hours) === '' || stripos($hours, '-') === false) {
            return [null, null];
        }

        [$openStr, $closeStr] = array_map('trim', explode('-', $hours, 2));

        $open = $this->makeTime($ref, $this->ensureSeconds($openStr));
        $close = $this->makeTime($ref, $this->ensureSeconds($closeStr));

        return [$open, $close];
    }

    protected function nextWorkingDayOpen(Carbon $start): Carbon
    {
        $cursor = $start->copy();
        for ($i = 0; $i < 14; $i++) {
            if ($this->isWorkingDay($cursor)) {
                [$open, $close] = $this->getOpenCloseForDayName($cursor->dayName, $cursor);
                if ($open && $close) {
                    return $open->copy();
                }
            }
            $cursor->addDay();
        }

        return $this->now->copy();
    }

    protected function isWorkingDay(Carbon $date): bool
    {
        if (empty($this->workingDays)) {
            return true;
        }
        $name = $this->normalizeDayName($date->dayName);

        return in_array($name, $this->workingDays, true);
    }

    protected function normalizeDays(array $days): array
    {
        $norm = [];
        foreach ($days as $d) {
            $norm[] = $this->normalizeDayName((string)$d);
        }

        return array_values(array_unique(array_filter($norm)));
    }

    protected function normalizeDayName(string $day): string
    {
        $day = strtolower(trim($day));
        $map = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        return $map[$day] ?? ucfirst($day);
    }

    protected function makeTime(Carbon $ref, string $time): Carbon
    {
        $time = $this->ensureSeconds($time);
        [$h, $m, $s] = array_map('intval', explode(':', $time));

        return $ref->copy()->setTime($h, $m, $s);
    }

    protected function ensureSeconds(string $t): string
    {
        $t = trim($t);
        if ($t === '') {
            return '00:00:00';
        }
        $parts = explode(':', $t);

        return match (count($parts)) {
            1 => sprintf('%02d:00:00', (int)$parts[0]),
            2 => sprintf('%02d:%02d:00', (int)$parts[0], (int)$parts[1]),
            default => sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]),
        };
    }

    protected function looksLikeWeeklyMap(array $hours): bool
    {
        if ($hours === []) {
            return false;
        }
        $keys = array_map([$this, 'normalizeDayName'], array_keys($hours));
        $valid = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $matches = array_intersect($keys, $valid);

        return count($matches) >= 4;
    }
}
