<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;

class BuildScheduleResponseAction
{
    private const DAY_ORDER = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday'
    ];

    public function __construct(
        protected Model $resource,
        protected AppInterface $app,
        protected ?array $rules = null
    ) {
    }

    public function execute(): array
    {
        $rules = $this->rules ?? $this->fetchRules();
        $isConfigured = ! empty($rules);
        $days = $this->buildDays($rules, $isConfigured);

        return [
            'is_configured' => $isConfigured,
            'schedule_type' => $isConfigured ? ($rules[0]->metadata['schedule_type'] ?? 'weekly') : null,
            'days_count' => collect($days)->where('active', true)->count(),
            'days' => $days,
            'last_modified' => $isConfigured ? collect($rules)->max('updated_at') : null,
            'rules' => $rules,
            'exceptions' => $this->fetchExceptions(),
        ];
    }

    private function fetchRules(): array
    {
        return ScheduleRules::where('resources_id', $this->resource->getId())
            ->where('resources_type', $this->resource->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->where('is_deleted', false)
            ->get()
            ->all();
    }

    private function fetchExceptions(): array
    {
        return ScheduleException::where('resources_id', $this->resource->getId())
            ->where('resources_type', $this->resource->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', false)
            ->orderBy('window_start')
            ->get()
            ->all();
    }

    private function buildDays(array $rules, bool $isConfigured): array
    {
        if (! $isConfigured) {
            return array_map(
                fn (string $day) => ['day' => $day, 'active' => false, 'open' => null, 'close' => null, 'periods' => null],
                self::DAY_ORDER
            );
        }

        $activeDays = [];
        foreach ($rules as $rule) {
            $dayName = $rule->metadata['operation_day'] ?? null;
            if (! $dayName) {
                continue;
            }

            $metadata = $rule->metadata ?? [];
            $periods = $metadata['periods'] ?? null;

            $open = null;
            $close = null;

            // Normalize to periods format for consistency
            if (! $periods) {
                // Legacy single period: convert to periods array
                [$open, $close] = $this->parseHoursFromRule($rule);
                if ($open && $close) {
                    $periods = [['open' => $open, 'close' => $close]];
                }
            } elseif (count($periods) === 1) {
                // Single period: populate both open/close AND periods (backward compatibility)
                $open = $periods[0]['open'];
                $close = $periods[0]['close'];
            }
            // Multi-period: open/close stay null, only use periods

            $activeDays[$dayName] = [
                'day' => $dayName,
                'active' => true,
                'open' => $open,
                'close' => $close,
                'periods' => $periods
            ];
        }

        return array_map(
            fn (string $day) => $activeDays[$day] ?? ['day' => $day, 'active' => false, 'open' => null, 'close' => null, 'periods' => null],
            self::DAY_ORDER
        );
    }

    private function parseHoursFromRule(ScheduleRules $rule): array
    {
        if (! $rule->day_rrule || ! preg_match('/BYHOUR=([0-9,]+)/', $rule->day_rrule, $matches)) {
            return [null, null];
        }

        $hours = array_map('intval', explode(',', $matches[1]));
        $openHour = min($hours);
        $closeHour = max($hours) + 1;

        return [
            Carbon::createFromFormat('H:i', sprintf('%02d:00', $openHour))->format('g:i A'),
            Carbon::createFromFormat('H:i', sprintf('%02d:00', $closeHour))->format('g:i A'),
        ];
    }
}
