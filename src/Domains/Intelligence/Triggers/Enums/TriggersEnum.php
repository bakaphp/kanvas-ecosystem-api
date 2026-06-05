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
    case HANDOFF = 10;
    case FOLLOW_UP_ON = 11;
    case FOLLOW_UP_OFF = 12;

    /**
     * Triggers that are manually flipped by humans (vs system-fired).
     * @return array<int, self>
     */
    public static function manualTriggers(): array
    {
        return [
            self::MANUAL_OFF,
            self::MANUAL_SUPPORT,
            self::MANUAL_FON,
        ];
    }

    /**
     * @return array<int, int>
     */
    public static function manualTriggerValues(): array
    {
        return array_map(fn (self $t) => $t->value, self::manualTriggers());
    }

    public function isManual(): bool
    {
        return in_array($this, self::manualTriggers(), true);
    }
}
