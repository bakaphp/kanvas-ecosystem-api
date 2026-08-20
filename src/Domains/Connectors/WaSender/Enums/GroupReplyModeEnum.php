<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

/**
 * Whether the agent speaks back into a group. It never gates ingest or processing — a silent
 * group still files its messages, still runs the agent, and still fires its workflow.
 */
enum GroupReplyModeEnum: string
{
    case NEVER = 'never';
    case MENTION = 'mention';
    case ALWAYS = 'always';

    public static function tryFromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::MENTION : self::MENTION;
    }
}
