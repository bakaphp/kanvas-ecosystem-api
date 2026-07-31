<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Jobs;

use Baka\Contracts\AppInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Inventory\Variants\Models\Variants;
use RRule\RRule;

class GenerateTimeSlots implements ShouldQueue
{
    public const DEFAULT_HORIZON_DAYS = 60;

    public function __construct(
        public int $resourceId,
        public int $ruleId,
        public Carbon $windowFrom,
        public Carbon $windowTo,
    ) {
    }

    /**
     * Resolve the [from, to] generation window for a rule: never backfill the past, and cap
     * the upper end at the booking horizon (never a year of mostly-unsold slots). The daily
     * top-up command keeps rolling the window forward, so a short horizon doesn't starve
     * long-lived rules — and re-upserting inside the horizon refreshes price snapshots.
     * Shared by both schedule mutations so they behave identically.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolveWindow(
        ?Carbon $startAt,
        ?Carbon $endAt,
        ?int $horizonDays = null,
    ): array {
        $now = Carbon::now();

        $windowFrom = $startAt && $startAt->greaterThan($now) ? $startAt->clone() : $now;
        $horizonEnd = $now->clone()->addDays($horizonDays ?? self::DEFAULT_HORIZON_DAYS);
        $windowTo = $endAt && $endAt->lessThan($horizonEnd) ? $endAt->clone() : $horizonEnd;

        return [$windowFrom, $windowTo];
    }

    public static function horizonDaysForApp(AppInterface $app): int
    {
        $configured = (int) $app->get(ConfigurationEnum::BOOKING_HORIZON_DAYS->value);

        return $configured > 0 ? $configured : self::DEFAULT_HORIZON_DAYS;
    }

    public function handle()
    {
        $rule       = ScheduleRules::findOrFail($this->ruleId);
        $resource   = $rule->resource;
        $tz         = ResourceTimezoneService::resolve($resource);

        $rrule = RRule::createFromRfcString($rule->rrule, $rule->start_at->setTimezone($tz));
        if ($rule->end_at) {
            $rrule->getOccurrencesBefore($rule->end_at->setTimezone($tz));
        }
        // Expand from the start of the day, not from `now`: a day occurrence carries DTSTART's
        // time-of-day, so a rule that opens at 09:00 would drop today entirely once it's past 09:00
        // — even though the rest of the day is still bookable. Slots already in the past are
        // discarded per-slot in createTimeSlot().
        $occurrences = $rrule->getOccurrencesBetween(
            $this->windowFrom->clone()->tz($tz)->startOfDay(),
            $this->windowTo->clone()->tz($tz)
        );

        foreach ($occurrences as $dayOccurrence) {
            $dayOccurrence = Carbon::instance($dayOccurrence);

            if ($rule->day_rrule) {
                $this->generateSlotsForDayWithRRule($rule, $resource, $dayOccurrence, $tz);
            } else {
                $localStart = $dayOccurrence;
                $localEnd = $localStart->copy()->addMinutes($rule->slot_duration_min);

                $this->createTimeSlot($rule, $resource, $localStart, $localEnd, $tz);
            }
        }
    }

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

    protected function createTimeSlot(
        ScheduleRules $rule,
        $resource,
        Carbon $localStart,
        Carbon $localEnd,
        string $tz
    ): void {
        if ($localStart->lt($this->windowFrom)) {
            return;
        }

        if ($this->isBlackedOut($resource->id, $localStart, $localEnd, $tz)) {
            return;
        }

        // Variants have no default_capacity column, so a rule without capacity_override must
        // still land on a non-null value — the column is NOT NULL and a null here fails the
        // whole generation job.
        $capacity = $rule->capacity_override ?? $resource->default_capacity ?? 1;
        $price = $this->resolvePrice($resource);

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

    /**
     * The default channel is the source of truth, but a variant never attached to it has no pivot
     * row (and an app with no default channel throws outright) — either case would kill the whole
     * generation run. A 0 channel price means the same thing in practice: the price was only ever
     * set on the warehouse, which is what the UI shows as the variant's price.
     */
    protected function resolvePrice(mixed $resource): ?float
    {
        if (! $resource instanceof Variants) {
            return null;
        }

        try {
            $channelPrice = (float) ($resource->getChannelInfo()?->price ?? 0);
        } catch (ModelNotFoundException) {
            $channelPrice = 0.0;
        }

        if ($channelPrice > 0) {
            return $channelPrice;
        }

        $warehousePrice = $resource->variantWarehouses()->value('price');

        return $warehousePrice !== null ? (float) $warehousePrice : null;
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
