<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Enums;

use Illuminate\Support\Carbon;

enum BillingCadenceEnum: string
{
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';
    case ONCE = 'once';

    /**
     * The period after $period, pinned to the anchor day. The day is clamped to the month's length so a
     * schedule anchored on the 31st bills on Feb 28 and goes back to the 31st in March, instead of
     * drifting to the 28th forever the way addMonth() on a clamped date would.
     */
    public function nextPeriod(Carbon $period, ?int $anchorDay = null): ?Carbon
    {
        $months = match ($this) {
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::YEARLY => 12,
            self::ONCE => null,
        };

        if ($months === null) {
            return null;
        }

        $next = $period->copy()->startOfMonth()->addMonthsNoOverflow($months);
        $day = $anchorDay ?? $period->day;

        return $next->setDay(min($day, $next->daysInMonth));
    }
}
