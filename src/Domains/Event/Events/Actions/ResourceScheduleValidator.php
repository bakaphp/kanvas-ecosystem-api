<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;

class ResourceScheduleValidator
{
    private const DEFAULT_TIMEZONE = 'America/Santo_Domingo';

    protected Model $scheduled;
    protected string $tz;

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

        if (! $rule) {
            return false;
        }

        return $this->isWithinOperatingHours($rule, $datetime);
    }

    public function validateAvailability(Carbon $startTime): void
    {
        if (! $this->hasScheduleConfigured()) {
            return;
        }

        if (! $this->isOpen($startTime)) {
            throw new ValidationException('Resource is closed at the requested time');
        }
    }

    public function hasScheduleConfigured(): bool
    {
        return ScheduleRules::where('resources_id', $this->scheduled->getId())
            ->where('resources_type', $this->scheduled->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
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
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->where('is_deleted', false)
            ->exists();
    }

    private function getResourceTimezone(): string
    {
        return $this->scheduled->tz
            ?? $this->scheduled->company->timezone
            ?? self::DEFAULT_TIMEZONE;
    }

    private function findRuleForDay(Carbon $datetime): ?ScheduleRules
    {
        $dayName = strtolower($datetime->format('l'));

        return ScheduleRules::where('resources_id', $this->scheduled->getId())
            ->where('resources_type', $this->scheduled->getMorphClass())
            ->where('apps_id', $this->app->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->whereJsonContains('metadata->operation_day', $dayName)
            ->where('is_deleted', false)
            ->first();
    }

    private function isWithinOperatingHours(ScheduleRules $rule, Carbon $datetime): bool
    {
        $dayRrule = $rule->day_rrule;

        if (! $dayRrule) {
            return true;
        }

        if (preg_match('/BYHOUR=([0-9,]+)/', $dayRrule, $matches)) {
            $hours = array_map('intval', explode(',', $matches[1]));
            $openHour = min($hours);
            $closeHour = max($hours) + 1;

            $currentMinutes = (int) $datetime->format('H') * 60 + (int) $datetime->format('i');
            $openMinutes = $openHour * 60;
            $closeMinutes = $closeHour * 60;

            return $currentMinutes >= $openMinutes && $currentMinutes < $closeMinutes;
        }

        if (preg_match('/DTSTART:(\d{8}T\d{6})/', $dayRrule, $matches)) {
            $startTime = Carbon::createFromFormat('Ymd\THis', $matches[1]);

            return $datetime->format('H:i') >= $startTime->format('H:i');
        }

        return true;
    }
}
