<?php

declare(strict_types=1);

namespace Kanvas\Companies\Services;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;

class BusinessDayCalculator
{
    public function __construct(
        protected Companies $company
    ) {
    }

    /**
     * Calculate business days between two dates, excluding holidays and half-days.
     * Half-days count as 0.5 days.
     *
     * @param Carbon $startDate The start date for calculation
     * @param Carbon $endDate The end date for calculation
     * @return float The number of business days (can include 0.5 for half-days)
     */
    public function calculateBusinessDays(
        Carbon $startDate,
        Carbon $endDate
    ): float {
        $businessDays = 0;
        $current = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        // Get custom special days with half-day configuration
        // Format: [['date' => '2025-12-24', 'type' => 'half_day', 'name' => 'Christmas Eve'], ...]
        $specialDays = $this->company->get(ConfigurationEnum::SPECIAL_DAYS->value) ?? [];

        $specialDaysLookup = [];
        foreach ($specialDays as $specialDay) {
            if (isset($specialDay['date'])) {
                $specialDaysLookup[$specialDay['date']] = $specialDay;
            }
        }

        while ($current->lt($end)) {
            $dateKey = $current->format('Y-m-d');
            // Check for custom special days first (they override weekend rules)
            if (isset($specialDaysLookup[$dateKey])) {
                $specialDay = $specialDaysLookup[$dateKey];

                if ($specialDay['type'] === 'full_day') {
                    // Skip full day
                    $current->addDay();
                    continue;
                } elseif ($specialDay['type'] === 'half_day') {
                    // Count as half day
                    $businessDays += 0.5;
                    $current->addDay();
                    continue;
                }
            }

            // Check for weekends
            if ($current->isSunday()) {
                // Sunday - skip full day
                $current->addDay();
                continue;
            } elseif ($current->isSaturday()) {
                // Saturday - count as half day
                $businessDays += 0.5;
                $current->addDay();
                continue;
            }

            // Regular business day (Monday-Friday, not a special day)
            $businessDays++;
            $current->addDay();
        }

        return $businessDays;
    }
}
