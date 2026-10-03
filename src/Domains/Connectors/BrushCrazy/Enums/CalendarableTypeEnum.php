<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * The three concrete tables behind BrushCrazy's abstract `Calendarable`. The case values are the
 * morph aliases from `Relation::enforceMorphMap()` in BrushCrazy's AppServiceProvider, which is
 * also what `registrations.calendarable_type` and `sale_items.option_type` store.
 */
enum CalendarableTypeEnum: string
{
    case LESSON = 'lesson';
    case EVENT = 'event';
    case WORKSHOP = 'workshop';

    public function table(): string
    {
        return match ($this) {
            self::LESSON => 'lessons',
            self::EVENT => 'events',
            self::WORKSHOP => 'workshops',
        };
    }

    /**
     * BrushCrazy renames `lesson` to `class` for display (`Calendarable::type()`), so the Kanvas
     * EventType follows the customer-facing word rather than the table name.
     */
    public function eventTypeName(): string
    {
        return match ($this) {
            self::LESSON => 'Class',
            self::EVENT => 'Private Event',
            self::WORKSHOP => 'Workshop',
        };
    }

    /**
     * Classes and workshops are the same session run many times, so they collapse into one Event
     * with an EventVersion per occurrence. Private parties are one-offs — grouping them would
     * merge unrelated bookings under a single Event.
     */
    public function isGrouped(): bool
    {
        return $this !== self::EVENT;
    }

    /** Only private events carry the approval + deposit ladder; the other two only publish. */
    public function hasApprovalLadder(): bool
    {
        return $this === self::EVENT;
    }
}
