<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class ResourceScheduleValidator
{
    protected Model $scheduled;
    protected string $tz;

    private const int MINUTES_PER_DAY = 1440;

    public function __construct(
        protected Model $resource,
        protected ?AppInterface $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
        $this->scheduled = $this->resolveScheduledResource();
        $this->tz = $this->getResourceTimezone();
    }

    public function isOpen(?Carbon $datetime = null): bool
    {
        $datetime = $this->normalizeToResourceTimezone($datetime);
        $morphClass = $this->scheduled->getMorphClass();
        $resourceId = $this->scheduled->getId();
        $appId = $this->app->getId();

        $blackout = ScheduleException::where('resources_id', $resourceId)
            ->where('resources_type', $morphClass)
            ->where('apps_id', $appId)
            ->where('kind', 'blackout')
            ->where('window_start', '<=', $datetime)
            ->where('window_end', '>=', $datetime)
            ->where('is_deleted', false)
            ->exists();

        if ($blackout) {
            return false;
        }

        $extraOpen = ScheduleException::where('resources_id', $resourceId)
            ->where('resources_type', $morphClass)
            ->where('apps_id', $appId)
            ->where('kind', 'extra_open')
            ->where('window_start', '<=', $datetime)
            ->where('window_end', '>=', $datetime)
            ->where('is_deleted', false)
            ->exists();

        if ($extraOpen) {
            return true;
        }

        if (! $this->hasScheduleConfigured()) {
            return true;
        }

        $rule = $this->findRuleForDay($datetime);

        if ($rule && $this->isWithinOperatingHours($rule, $datetime)) {
            return true;
        }

        return $this->isWithinPreviousDayOvernightTail($datetime);
    }

    public function validateAvailability(Carbon $startTime): void
    {
        if (! $this->hasScheduleConfigured()) {
            return;
        }

        if (! $this->isOpen($startTime)) {
            $message = $this->buildClosedErrorMessage($startTime);

            throw new ValidationException($message);
        }
    }

    public function hasScheduleConfigured(): bool
    {
        return ScheduleRules::where('resources_id', $this->scheduled->getId())
            ->where('resources_type', $this->scheduled->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->whereNotNull('operation_day')
            ->where('is_deleted', false)
            ->exists();
    }

    /**
     * Static convenience methods for backward compatibility and simple calls.
     */
    public static function isResourceOpen(Model $resource, ?Carbon $datetime = null, ?AppInterface $app = null): bool
    {
        return new self($resource, $app)->isOpen($datetime);
    }

    public static function validateResourceAvailability(Model $resource, Carbon $startTime, ?AppInterface $app = null): void
    {
        new self($resource, $app)->validateAvailability($startTime);
    }

    private function normalizeToResourceTimezone(?Carbon $datetime): Carbon
    {
        if ($datetime === null) {
            return Carbon::now($this->tz);
        }

        $serverTz = config('app.timezone', 'UTC');

        if ($datetime->timezoneName === $serverTz) {
            return $datetime->clone()->shiftTimezone($this->tz);
        }

        return $datetime->clone()->setTimezone($this->tz);
    }

    private function resolveScheduledResource(): Model
    {
        $appId = $this->app->getId();

        if ($this->hasScheduleForResource($this->resource, $appId)) {
            return $this->resource;
        }

        if ($this->resource instanceof Variants && $this->resource->product) {
            return $this->resource->product;
        }

        return $this->resource;
    }

    private function hasScheduleForResource(Model $resource, int $appId): bool
    {
        return ScheduleRules::where('resources_id', $resource->getId())
            ->where('resources_type', $resource->getMorphClass())
            ->where('apps_id', $appId)
            ->whereNotNull('operation_day')
            ->where('is_deleted', false)
            ->exists();
    }

    private function getResourceTimezone(): string
    {
        return ResourceTimezoneService::resolve($this->scheduled);
    }

    private function findRuleForDay(Carbon $datetime): ?ScheduleRules
    {
        $dayName = strtolower($datetime->format('l'));

        return ScheduleRules::where('resources_id', $this->scheduled->getId())
            ->where('resources_type', $this->scheduled->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->where('operation_day', $dayName)
            ->where('is_deleted', false)
            ->first();
    }

    private function isWithinOperatingHours(ScheduleRules $rule, Carbon $datetime): bool
    {
        $periods = $this->resolveOperatingPeriods($rule);

        if ($periods === null) {
            return true;
        }

        $currentMinutes = (int) $datetime->format('H') * 60 + (int) $datetime->format('i');

        foreach ($periods as $period) {
            $closesNextDay = $period['close'] !== null && $period['close'] <= $period['open'];

            if ($currentMinutes < $period['open']) {
                continue;
            }

            if ($closesNextDay || $currentMinutes < ($period['close'] ?? self::MINUTES_PER_DAY)) {
                return true;
            }
        }

        return false;
    }

    private function isWithinPreviousDayOvernightTail(Carbon $datetime): bool
    {
        $rule = $this->findRuleForDay($datetime->copy()->subDay());
        $periods = $rule ? $this->resolveOperatingPeriods($rule) : null;

        if ($periods === null) {
            return false;
        }

        $currentMinutes = (int) $datetime->format('H') * 60 + (int) $datetime->format('i');

        foreach ($periods as $period) {
            if ($period['close'] !== null && $period['close'] <= $period['open'] && $currentMinutes < $period['close']) {
                return true;
            }
        }

        return false;
    }

    private function resolveOperatingPeriods(ScheduleRules $rule): ?array
    {
        $metadata = $rule->metadata ?? [];

        if (isset($metadata['periods']) && is_array($metadata['periods'])) {
            return array_map(
                fn (array $period): array => [
                    'open' => $this->clockToMinutes($period['open']),
                    'close' => $this->clockToMinutes($period['close']),
                ],
                $metadata['periods']
            );
        }

        $dayRrule = $rule->day_rrule;

        if (! $dayRrule) {
            return null;
        }

        if (preg_match('/BYHOUR=([0-9,]+)/', $dayRrule, $matches)) {
            $hours = array_map('intval', explode(',', $matches[1]));

            return [['open' => min($hours) * 60, 'close' => (max($hours) + 1) * 60]];
        }

        if (preg_match('/DTSTART:(\d{8}T\d{6})/', $dayRrule, $matches)) {
            $startTime = Carbon::createFromFormat('Ymd\THis', $matches[1]);

            return [['open' => (int) $startTime->format('H') * 60 + (int) $startTime->format('i'), 'close' => null]];
        }

        return null;
    }

    private function clockToMinutes(string $clock): int
    {
        $time = Carbon::parse('today ' . $clock);

        return (int) $time->format('H') * 60 + (int) $time->format('i');
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function buildClosedErrorMessage(Carbon $requestedTime): string
    {
        $resourceName = $this->scheduled->name ?? 'Resource';
        $normalizedTime = $this->normalizeToResourceTimezone($requestedTime);
        $formattedTime = $normalizedTime->format('Y-m-d H:i');

        $rule = $this->findRuleForDay($normalizedTime);

        if (! $rule) {
            $dayName = $normalizedTime->format('l');

            return "{$resourceName} is closed on {$dayName}s. Requested time: {$formattedTime}";
        }

        $operatingHours = $this->extractOperatingHours($rule);

        return "{$resourceName} is closed at the requested time ({$formattedTime}). Operating hours: {$operatingHours}";
    }

    private function extractOperatingHours(ScheduleRules $rule): string
    {
        $periods = $this->resolveOperatingPeriods($rule);

        if ($periods === null) {
            return 'not configured';
        }

        return implode(', ', array_map(
            fn (array $period): string => $period['close'] === null
                ? $this->formatMinutes($period['open']) . ' onwards'
                : $this->formatMinutes($period['open']) . ' - ' . $this->formatMinutes($period['close']),
            $periods
        ));
    }
}
