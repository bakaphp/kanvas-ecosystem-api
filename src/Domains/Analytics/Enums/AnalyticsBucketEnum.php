<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Enums;

enum AnalyticsBucketEnum: string
{
    case DAY = 'DAY';
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';
}
