<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum IntelligenceModeEnum: string
{
    case FULL_ON = 'FULL_ON';
    case SUPPORT = 'SUPPORT';
    case IDLE = 'IDLE';
    /** @deprecated use IDLE */
    case OFF = 'OFF';
    case AI_FOLLOW_UP = 'ai_follow_up';
    case DEFAULT_AI_FOLLOW_UP_TYPE = 'default_ai_follow_up_type';

    public function isOff(): bool
    {
        return $this === self::IDLE || $this === self::OFF;
    }
}
