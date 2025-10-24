<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Helpers;

use DateTime;
use Illuminate\Support\Carbon;

class DateHelper
{
    /**
     * Generate a list of dates between start and end dates
     *
     * @param Carbon|string $start
     * @param Carbon|string $end
     */
    public static function generateDateList($start, $end): array
    {
        $dates = [];
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);

        while ($startDate <= $endDate) {
            $dates[] = "'" . $startDate->format('Y-m-d') . "'";
            $startDate->modify('+1 day');
        }

        return $dates;
    }
}
