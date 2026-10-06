<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Kanvas\Connectors\BrushCrazy\Enums\EventClassEnum;
use Kanvas\Connectors\BrushCrazy\Support\CalendarableName;

class EventClassMapper
{
    /**
     * Precedence is strict and load-bearing: a drop-in session can also be flagged private, and a
     * private party can also be a fundraiser. Reordering these silently reclassifies rows.
     */
    public static function resolve(object $row): EventClassEnum
    {
        if (CalendarableName::isDropInPaint($row->name ?? null)) {
            return EventClassEnum::DROP_IN;
        }

        if ((bool) ($row->private ?? false)) {
            return EventClassEnum::PRIVATE;
        }

        if ((int) ($row->fundraiser ?? 0) > 0) {
            return EventClassEnum::FUNDRAISER;
        }

        return EventClassEnum::PUBLIC;
    }
}
