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
            $message = $this->buildClosedErrorMessage($startTime);
            throw new ValidationException($message);
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
        // Check if rule has multiple periods (split schedule support)
        $metadata = $rule->metadata ?? [];
        if (isset($metadata['periods']) && is_array($metadata['periods'])) {
            return $this->isWithinAnyPeriod($metadata['periods'], $datetime);
        }

        // Fallback to legacy single period validation
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

    private function isWithinAnyPeriod(array $periods, Carbon $datetime): bool
    {
        $currentMinutes = (int) $datetime->format('H') * 60 + (int) $datetime->format('i');

        foreach ($periods as $period) {
            $openTime = Carbon::parse('today ' . $period['open']);
            $closeTime = Carbon::parse('today ' . $period['close']);

            $openMinutes = (int) $openTime->format('H') * 60 + (int) $openTime->format('i');
            $closeMinutes = (int) $closeTime->format('H') * 60 + (int) $closeTime->format('i');

            if ($currentMinutes >= $openMinutes && $currentMinutes < $closeMinutes) {
                return true;
            }
        }

        return false;
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
        // Check if rule has multiple periods (split schedule)
        $metadata = $rule->metadata ?? [];
        if (isset($metadata['periods']) && is_array($metadata['periods'])) {
            $periodStrings = [];
            foreach ($metadata['periods'] as $period) {
                $periodStrings[] = $period['open'] . ' - ' . $period['close'];
            }
            return implode(', ', $periodStrings);
        }

        // Fallback to legacy single period extraction
        $dayRrule = $rule->day_rrule;

        if (! $dayRrule) {
            return 'not configured';
        }

        if (preg_match('/BYHOUR=([0-9,]+)/', $dayRrule, $matches)) {
            $hours = array_map('intval', explode(',', $matches[1]));
            $openHour = min($hours);
            $closeHour = max($hours) + 1;

            return sprintf('%02d:00 - %02d:00', $openHour, $closeHour);
        }

        if (preg_match('/DTSTART:(\d{8}T\d{6})/', $dayRrule, $matches)) {
            $startTime = Carbon::createFromFormat('Ymd\THis', $matches[1]);
            return $startTime->format('H:i') . ' onwards';
        }

        return 'not configured';
    }
}
