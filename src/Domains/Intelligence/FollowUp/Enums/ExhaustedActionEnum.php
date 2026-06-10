<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum ExhaustedActionEnum: string
{
    case STOP = 'stop';
    case ADVANCE = 'advance';
}
