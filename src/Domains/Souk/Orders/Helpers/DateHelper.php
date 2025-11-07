<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Helpers;

use Illuminate\Support\Carbon;

class DateHelper
{
    /**
     * Generate a list of dates between start and end dates
     */
    public static function generateDateList(Carbon $start, Carbon $end): array
    {
        $dates = [];
        $startDate = $start->copy();
        $endDate = $end->copy();

        while ($startDate <= $endDate) {
            $dates[] = "'" . $startDate->format('Y-m-d') . "'";
            $startDate->addDay();
        }

        return $dates;
    }
}
