<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Enums;

enum TriggersEnum: int
{
    case NEW_LEAD = 1;
    case HUMAN_HANDOFF = 2;
    case HUMAN_TAKEOVER = 3;
    case AI_TAKEOVER = 4;
    case SOLD_LEAD = 5;
    case CLOSE_LEAD = 6;
    case MANUAL_OFF = 7;
    case MANUAL_SUPPORT = 8;
    case MANUAL_FON = 9;
}
