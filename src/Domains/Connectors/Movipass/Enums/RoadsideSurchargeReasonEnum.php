<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideSurchargeReasonEnum: string
{
    case PEAK_HOUR = 'peak_hour';
    case HOLIDAY = 'holiday';
    case LATE_NIGHT = 'late_night';
}
