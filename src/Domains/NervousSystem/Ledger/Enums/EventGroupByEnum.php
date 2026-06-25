<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Enums;

// Column the analytics service groups events by. Values map 1:1 to event columns.
// Time-bucketing (day/week/month) is intentionally not here — when needed, use a
// shared date enum rather than re-declaring the triple a 4th time.
enum EventGroupByEnum: string
{
    case EVENT_TYPE = 'event_type';
    case ACTOR_TYPE = 'actor_type';
    case SOURCE_ENTITY_TYPE = 'source_entity_type';
}
