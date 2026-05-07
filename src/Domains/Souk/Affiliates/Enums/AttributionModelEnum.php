<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum AttributionModelEnum: string
{
    case FIRST_CLICK = 'first_click';
    case LAST_CLICK = 'last_click';
    case LINEAR = 'linear';
    case TIME_DECAY = 'time_decay';
}
