<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Support\Carbon;

/**
 * Resolves a calendarable's start/end into a UTC instant plus the wall clock to store on
 * `event_version_dates`.
 *
 * BrushCrazy stores these columns in UTC, but converts through a hardcoded `America/Denver`
 * (`Entity::TIMEZONE`) regardless of where the studio actually is. Columbus OH runs on
 * `America/New_York`, so its ~8,100 rows carry an instant two hours off from the wall clock staff
 * entered — while its comparison logic (`isPast()`, `isNotOpenForRegistrations()`) uses the real
 * studio zone. That inconsistency is the source bug.
 *
 * Per product decision: only rows starting at or after the cutoff are corrected; earlier rows keep
 * the stored instant so they still reconcile against the legacy UI. That deliberately leaves two
 * semantics in one table, so every result reports which one it used and callers persist it.
 */
final readonly class EventInstant
{
    /** The zone BrushCrazy converts through, irrespective of the studio's real zone. */
    public const SOURCE_TIMEZONE = 'America/Denver';

    private function __construct(
        public Carbon $startAt,
        public ?Carbon $endAt,
        public string $displayTimezone,
        public bool $corrected,
    ) {
    }

    public static function resolve(
        ?string $rawStart,
        ?string $rawEnd,
        string $studioTimezone,
        Carbon $correctionCutoff,
    ): ?self {
        if ($rawStart === null || trim($rawStart) === '') {
            return null;
        }

        $storedStart = Carbon::parse($rawStart, 'UTC');
        $storedEnd = $rawEnd !== null && trim($rawEnd) !== '' ? Carbon::parse($rawEnd, 'UTC') : null;

        // A Denver studio has nothing to correct — source and real zone already agree.
        $correctable = $studioTimezone !== self::SOURCE_TIMEZONE;
        $corrected = $correctable && $storedStart->greaterThanOrEqualTo($correctionCutoff);

        if (! $corrected) {
            return new self(
                $storedStart,
                $storedEnd,
                self::SOURCE_TIMEZONE,
                false
            );
        }

        return new self(
            self::reinterpret($storedStart, $studioTimezone),
            $storedEnd !== null ? self::reinterpret($storedEnd, $studioTimezone) : null,
            $studioTimezone,
            true,
        );
    }

    /**
     * Takes the wall clock the source would display and re-reads it in the studio's real zone,
     * which is the instant the session actually happened at.
     */
    private static function reinterpret(Carbon $stored, string $studioTimezone): Carbon
    {
        $wallClock = $stored->copy()->setTimezone(self::SOURCE_TIMEZONE)->format('Y-m-d H:i:s');

        return Carbon::parse($wallClock, $studioTimezone)->utc();
    }

    /** `event_version_dates.event_date` — wall clock in whichever zone this instant was read as. */
    public function localDate(): string
    {
        return $this->startAt->copy()->setTimezone($this->displayTimezone)->format('Y-m-d');
    }

    public function localStartTime(): string
    {
        return $this->startAt->copy()->setTimezone($this->displayTimezone)->format('H:i:s');
    }

    public function localEndTime(): ?string
    {
        return $this->endAt?->copy()->setTimezone($this->displayTimezone)->format('H:i:s');
    }

    /**
     * Persisted on `event_versions.metadata` so the two semantics stay distinguishable after the
     * import, instead of being an invisible property of the row's date.
     */
    public function semanticsMetadata(Carbon $correctionCutoff): array
    {
        return [
            'tz_semantics' => $this->corrected ? 'corrected' : 'as_stored',
            'tz_display' => $this->displayTimezone,
            'tz_correction_cutoff' => $correctionCutoff->toIso8601String(),
        ];
    }
}
