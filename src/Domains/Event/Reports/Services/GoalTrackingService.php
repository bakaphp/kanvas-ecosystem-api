<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Services;

use Carbon\Carbon;

class GoalTrackingService
{
    /**
     * Percentage of the goal expected to be reached at each week before the event.
     * E.g., 7 weeks out we expect 5% of the goal; 1 week out we expect 100%.
     */
    public const array WEEK_PERCENTAGES = [
        7 => 0.05,
        6 => 0.10,
        5 => 0.20,
        4 => 0.40,
        3 => 0.60,
        2 => 0.80,
        1 => 1.00,
    ];

    public const string COLOR_GREEN = 'green';
    public const string COLOR_YELLOW = 'yellow';
    public const string COLOR_RED = 'red';

    /**
     * Expected enrollment for a given goal and weeks-until-event.
     */
    public function getExpectedEnrollment(int $goal, int $weeksUntilEvent): int
    {
        if ($goal <= 0) {
            return 0;
        }

        $weeksUntilEvent = max(1, min(7, $weeksUntilEvent));
        $percentage = self::WEEK_PERCENTAGES[$weeksUntilEvent] ?? 1.0;

        return (int) round((float) $goal * $percentage);
    }

    /**
     * Color code based on actual vs expected enrollment.
     * Green >= 86%, Yellow 70-85%, Red < 69%.
     */
    public function getColor(int $enrolled, int $expected): string
    {
        if ($expected <= 0) {
            return self::COLOR_GREEN;
        }

        $achievement = (float) $enrolled / (float) $expected;

        return match (true) {
            $achievement >= 0.86 => self::COLOR_GREEN,
            $achievement >= 0.70 => self::COLOR_YELLOW,
            default => self::COLOR_RED,
        };
    }

    /**
     * How many weeks until the event starts (rounded down, minimum 1).
     * Returns 0 if the event is in the past.
     */
    public function getWeeksUntil(Carbon $eventStart, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        if ($eventStart->lessThanOrEqualTo($now)) {
            return 0;
        }

        return (int) floor($now->diffInDays($eventStart) / 7) + 1;
    }

    /**
     * Achievement percentage: enrolled / expected (as 0-100 float).
     */
    public function getAchievementPercentage(int $enrolled, int $expected): float
    {
        if ($expected <= 0) {
            return $enrolled > 0 ? 100.0 : 0.0;
        }

        return round(((float) $enrolled / (float) $expected) * 100.0, 2);
    }
}
