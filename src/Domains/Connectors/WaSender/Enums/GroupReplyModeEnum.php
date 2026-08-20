<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

use Kanvas\Connectors\WaSender\Traits\ParsesEnumValueTrait;
use Override;

/**
 * Whether the agent speaks back into a group. It never gates ingest or processing — a silent
 * group still files its messages, still runs the agent, and still fires its workflow.
 */
enum GroupReplyModeEnum: string
{
    use ParsesEnumValueTrait;

    case NEVER = 'never';
    case MENTION = 'mention';
    case ALWAYS = 'always';

    #[Override]
    protected static function fallback(): self
    {
        return self::MENTION;
    }
}
